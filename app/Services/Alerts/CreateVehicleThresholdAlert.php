<?php

namespace App\Services\Alerts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Internal entry point for trusted domain evaluators; not a public API. */
class CreateVehicleThresholdAlert
{
    /**
     * Persist one occurrence and one inbox record per active privileged member.
     * Recipient selection is a snapshot at first creation. Replays do not add
     * new recipients or modify read state. Email intents commit with first creation.
     *
     * @return array{id: string, created: bool, recipient_count: int}
     */
    public function create(
        string $organizationId,
        string $vehicleId,
        string $ruleKey,
        int $ruleVersion,
        string $episodeKey,
        string $thresholdKey,
        string $kind,
        string $severity,
        DateTimeInterface $occurredAt,
        bool $notify = true,
        array $parameters = [],
        ?bool $emailNotify = null,
    ): array {
        if (!Str::isUuid($organizationId) || !Str::isUuid($vehicleId)) {
            throw new InvalidArgumentException('Organization and vehicle identifiers must be UUIDs.');
        }
        foreach ([$ruleKey, $episodeKey, $thresholdKey] as $key) {
            if (!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $key)) {
                throw new InvalidArgumentException('Invalid alert deduplication key.');
            }
        }
        if ($ruleVersion < 1 || !in_array($severity, ['info', 'warning', 'high', 'critical'], true)) {
            throw new InvalidArgumentException('Invalid alert version or severity.');
        }
        $templates = [
            'maintenance.threshold_crossed' => ['Maintenance threshold reached', 'A vehicle maintenance threshold requires review.', 'vehicle_maintenance'],
            'compliance.threshold_crossed' => ['Compliance threshold reached', 'A vehicle compliance threshold requires review.', 'vehicle_compliance'],
        ];
        if (!isset($templates[$kind])) throw new InvalidArgumentException('Unsupported alert kind.');
        $occurred = CarbonImmutable::instance($occurredAt)->utc();
        if ($occurred->greaterThan(now()->addMinutes(5))) throw new InvalidArgumentException('Alert occurrence time is in the future.');
        $key = hash('sha256', json_encode([$vehicleId, $ruleKey, $episodeKey, $thresholdKey, $kind], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($organizationId, $vehicleId, $ruleKey, $ruleVersion, $episodeKey, $thresholdKey, $kind, $severity, $occurred, $key, $templates, $notify, $parameters, $emailNotify) {
            // Shared tenant lock serializes concurrent first evaluation/replay.
            $organization = DB::table('organizations')->where('id', $organizationId)->where('status', 'active')->lockForUpdate()->first();
            if (!$organization) throw new InvalidArgumentException('Unknown or inactive organization.');
            $vehicle = DB::table('vehicles')->where('organization_id', $organizationId)->where('id', $vehicleId)->whereIn('status', ['active','maintenance'])->first();
            if (!$vehicle) throw new InvalidArgumentException('Unknown or inactive vehicle.');

            $existing = DB::table('alert_occurrences')->where('organization_id', $organizationId)->where('deduplication_hash', $key)->first();
            if ($existing) {
                return ['id' => $existing->id, 'created' => false, 'recipient_count' => DB::table('notification_records')->where('organization_id', $organizationId)->where('alert_occurrence_id', $existing->id)->count()];
            }
            $id = (string) Str::uuid();
            $now = now();
            DB::table('alert_occurrences')->insert([
                'id' => $id, 'organization_id' => $organizationId, 'vehicle_id' => $vehicleId,
                'rule_key' => $ruleKey, 'rule_version' => $ruleVersion, 'episode_key' => $episodeKey,
                'threshold_key' => $thresholdKey, 'kind' => $kind, 'severity' => $severity,
                'state' => 'open', 'deduplication_hash' => $key, 'occurred_at' => $occurred,
                'detected_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $permission = $kind === 'maintenance.threshold_crossed' ? 'maintenance.write' : 'compliance.write';
            $recipients = $notify ? \App\Models\Membership::where('organization_id',$organizationId)->where('status','active')->get()
                ->filter(fn ($membership) => app(\App\Services\Permissions::class)->allows($membership,$permission))->pluck('user_id') : collect();
            [$title, $message, $route] = $templates[$kind];
            foreach ($recipients as $userId) {
                DB::table('notification_records')->insert([
                    'id' => (string) Str::uuid(), 'organization_id' => $organizationId, 'user_id' => $userId,
                    'kind' => $kind, 'severity' => $severity, 'title' => $title, 'message' => $message,
                    'alert_occurrence_id' => $id, 'subject_type' => 'vehicle', 'subject_id' => $vehicleId,
                    'action_route' => $route, 'action_parameters' => json_encode(['vehicle_id' => $vehicleId], JSON_THROW_ON_ERROR),
                    'parameters' => json_encode(['threshold_key' => $thresholdKey] + $parameters, JSON_THROW_ON_ERROR),
                    'occurred_at' => $occurred, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            if ($emailNotify ?? $kind==='compliance.threshold_crossed') {
                $details=['Vehicle'=>$vehicle->registration_number,'Threshold'=>$thresholdKey];
                foreach (['due_date'=>'Due date','remaining_days'=>'Days remaining','remaining_km'=>'Estimated kilometres remaining'] as $field=>$label) if (array_key_exists($field,$parameters)) $details[$label]=$parameters[$field];
                if ($kind==='compliance.threshold_crossed') {
                    $obligation=substr($ruleKey,strlen('compliance:'));
                    $type=DB::table('compliance_obligations as o')->join('compliance_types as t',fn($j)=>$j->on('t.id','=','o.compliance_type_id')->on('t.organization_id','=','o.organization_id'))->where('o.organization_id',$organizationId)->where('o.id',$obligation)->first(['t.name','t.warning_days']);
                    if ($type) $details+=['Compliance'=>$type->name,'Configured warning days'=>$type->warning_days];
                }
                app(\App\Services\Mail\NotificationEmails::class)->organization($kind,$id,$organizationId,$title,$message,$details,[$permission],['alert_id'=>$id,'subscription_required'=>false],
                    $kind==='maintenance.threshold_crossed' ? 'Mileage is a partial-coverage estimate, not a measured odometer. Unknown values are not treated as zero.' : 'Only verified coverage is used. Review and renew the applicable record in CarLink.');
            }
            return ['id' => $id, 'created' => true, 'recipient_count' => $recipients->count()];
        });
    }
}
