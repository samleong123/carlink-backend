<?php

namespace App\Services\Alerts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Bounded pilot evaluator for vehicle calendar obligations only. */
class EvaluateVehicleCompliance
{
    public function __construct(private readonly CreateVehicleThresholdAlert $alerts) {}

    /** @return array{scanned:int,created:int} */
    public function run(int $limit = 200): array
    {
        if ($limit < 1 || $limit > 1000) throw new \InvalidArgumentException('Batch limit must be 1 to 1000.');

        return DB::transaction(function () use ($limit) {
            DB::table('compliance_evaluation_cursors')->insertOrIgnore(['name' => 'vehicle_calendar_v1', 'last_obligation_id' => null, 'updated_at' => now()]);
            $cursor = DB::table('compliance_evaluation_cursors')->where('name', 'vehicle_calendar_v1')->lockForUpdate()->first();
            $query = DB::table('compliance_obligations as o')
                ->join('organizations as org', 'org.id', '=', 'o.organization_id')
                ->join('compliance_types as t', function ($join) { $join->on('t.id', '=', 'o.compliance_type_id')->on('t.organization_id', '=', 'o.organization_id'); })
                ->join('vehicles as v', function ($join) { $join->on('v.id', '=', 'o.vehicle_id')->on('v.organization_id', '=', 'o.organization_id'); })
                ->where('o.subject_type', 'vehicle');
            if ($cursor->last_obligation_id !== null) $query->where('o.id', '>', $cursor->last_obligation_id);
            $batch = $query->orderBy('o.id')->limit($limit)->get(['o.id', 'o.organization_id']);
            $created = 0;
            foreach ($batch as $candidate) $created += $this->evaluate($candidate->organization_id, $candidate->id) ? 1 : 0;
            DB::table('compliance_evaluation_cursors')->where('name', 'vehicle_calendar_v1')->update([
                'last_obligation_id' => $batch->count() < $limit ? null : $batch->last()->id,
                'updated_at' => now(),
            ]);
            return ['scanned' => $batch->count(), 'created' => $created];
        });
    }

    private function evaluate(string $organizationId, string $obligationId): bool
    {
        // Lock in the same tenant-first order as the alert producer, then lock
        // the obligation so verification and suspension cannot race evaluation.
        $organization = DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first();
        $obligation = DB::table('compliance_obligations')->where('organization_id', $organizationId)->where('id', $obligationId)->lockForUpdate()->first();
        if (!$organization || !$obligation || $obligation->subject_type !== 'vehicle') return false;
        $type = DB::table('compliance_types')->where('organization_id', $organizationId)->where('id', $obligation->compliance_type_id)->where('active', true)->first();
        $vehicle = DB::table('vehicles')->where('organization_id', $organizationId)->where('id', $obligation->vehicle_id)->where('status', 'active')->first();
        if ($organization->status !== 'active' || $obligation->applicability !== 'applicable' || !$type || !$vehicle) {
            $this->supersede($organizationId, $obligationId, null, null, 'obligation_inactive');
            return false;
        }
        try { $today = CarbonImmutable::now($organization->timezone ?: 'Asia/Kuala_Lumpur')->startOfDay(); }
        catch (\Throwable $error) { return false; }
        $date = $today->toDateString();
        $base = fn () => DB::table('compliance_records')->where('organization_id', $organizationId)->where('obligation_id', $obligationId)->where('state', 'verified')->where('valid_from', '<=', $date);
        $current = $base()->where('expires_on', '>=', $date)->where(fn ($q) => $q->whereNull('suspended_at')->orWhere('suspended_at', '>', now()))->orderByDesc('expires_on')->orderByDesc('valid_from')->first();
        $record = $current ?? $base()->orderByDesc('expires_on')->orderByDesc('valid_from')->first();
        if (!$record || ($record->suspended_at !== null && CarbonImmutable::parse($record->suspended_at)->lessThanOrEqualTo(now()))) {
            $this->supersede($organizationId, $obligationId, null, null, 'coverage_not_active');
            return false;
        }
        $episode = 'record:'.$record->id;
        $this->supersede($organizationId, $obligationId, $episode, null, 'coverage_replaced');
        // These are calendar dates, not elapsed 24-hour windows. Comparing
        // their UTC representations avoids DST shifts changing a day count.
        $calendarToday = CarbonImmutable::parse($date, 'UTC')->startOfDay();
        $calendarExpiry = CarbonImmutable::parse($record->expires_on, 'UTC')->startOfDay();
        $remaining = (int) $calendarToday->diffInDays($calendarExpiry, false);
        if ($remaining < 0) {
            $threshold = 'expired'; $severity = 'critical';
            $this->supersede($organizationId, $obligationId, null, $episode, 'escalated_to_expired');
        } elseif ($remaining <= (int) $type->warning_days) {
            // A verified successor extending coverage by the next calendar day
            // removes the need to renew the current record. Pending, suspended,
            // non-extending or gapped records do not suppress its warning.
            $nextDay = $calendarExpiry->addDay()->toDateString();
            $continuous = DB::table('compliance_records')->where('organization_id', $organizationId)->where('obligation_id', $obligationId)->where('state', 'verified')
                ->where('valid_from', '>', $date)->where('valid_from', '<=', $nextDay)
                ->where('expires_on', '>', $record->expires_on)->whereNull('suspended_at')->exists();
            if ($continuous) {
                $this->supersede($organizationId, $obligationId, null, $episode, 'continuous_renewal_verified');
                return false;
            }
            // A future successor can be suspended after it suppressed an alert.
            // A new generation delivers another recipient notification without
            // altering a user's read/archive state on the older notification.
            $suppressions = DB::table('compliance_alert_transitions as tr')
                ->join('alert_occurrences as a', function ($join) { $join->on('a.id', '=', 'tr.alert_occurrence_id')->on('a.organization_id', '=', 'tr.organization_id'); })
                ->where('tr.organization_id', $organizationId)->where('a.rule_key', 'compliance:'.$obligationId)
                ->where('a.episode_key', $episode)->where('tr.reason', 'continuous_renewal_verified')->count();
            $threshold = 'warning:'.(int) $type->warning_days.($suppressions > 0 ? ':reopened:'.$suppressions : '');
            $severity = 'warning';
        } else return false;

        if ((int)$type->version>1) $threshold.=':configuration:'.$type->version;
        $result = $this->alerts->create($organizationId, $vehicle->id, 'compliance:'.$obligationId,
            (int) $type->version, $episode, $threshold, 'compliance.threshold_crossed', $severity, now(),true,['due_date'=>$record->expires_on,'remaining_days'=>$remaining]);
        return $result['created'];
    }

    /** Supersede all, other episodes, or warning stages in an expired episode. */
    private function supersede(string $org, string $obligation, ?string $exceptEpisode, ?string $warningEpisode, string $reason): void
    {
        $query = DB::table('alert_occurrences')->where('organization_id', $org)->where('rule_key', 'compliance:'.$obligation)
            ->where('kind', 'compliance.threshold_crossed')->where('state', 'open');
        if ($exceptEpisode !== null) $query->where('episode_key', '<>', $exceptEpisode);
        if ($warningEpisode !== null) $query->where('episode_key', $warningEpisode)->where('threshold_key', 'like', 'warning:%');
        foreach ($query->lockForUpdate()->get(['id', 'state']) as $occurrence) {
            DB::table('alert_occurrences')->where('id', $occurrence->id)->where('organization_id', $org)->update(['state' => 'superseded', 'updated_at' => now()]);
            DB::table('compliance_alert_transitions')->insert(['id' => (string) Str::uuid(), 'organization_id' => $org, 'alert_occurrence_id' => $occurrence->id, 'from_state' => $occurrence->state, 'to_state' => 'superseded', 'reason' => $reason, 'occurred_at' => now()]);
        }
    }
}
