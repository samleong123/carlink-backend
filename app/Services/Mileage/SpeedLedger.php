<?php

namespace App\Services\Mileage;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Bounded pilot projection; call only with an inbox record accepted by the broker consumer. */
class SpeedLedger
{
    public function project(string $inboxId): string
    {
        $inbox = DB::table('telemetry_inbox')->where('id', $inboxId)->first();
        if (!$inbox) throw new \InvalidArgumentException('Unknown inbox record.');
        $event = json_decode($inbox->raw_payload, true, 32, JSON_THROW_ON_ERROR);
        $snapshot = $inbox->source_format === 'pi_snapshot_v1';
        if ($snapshot) {
            $speed = $event['vehicle']['speed_kmh'] ?? null;
            if (($event['vehicle']['obd_connected'] ?? false) !== true || (!is_int($speed) && !is_float($speed)) || $speed < 0 || $speed > 250) return 'unusable';
            // sample_id is the packet event ID for snapshots, never an invented sensor identity.
            $m = ['value'=>$speed,'sample_id'=>$inbox->event_id,'observed_uptime_ms'=>null];
            $at = CarbonImmutable::parse($inbox->occurred_at)->utc();
        } else {
        $m = $event['payload']['vehicle']['speed_kmh'] ?? null;
        if (!is_array($m) || ($m['quality'] ?? null) !== 'valid' || !is_string($m['pid'] ?? null) || strtoupper($m['pid']) !== '010D' || !is_numeric($m['value'] ?? null)
            || !is_finite((float) $m['value']) || (float) $m['value'] < 0 || (float) $m['value'] > 250
            || !is_string($m['sample_id'] ?? null) || !Str::isUuid($m['sample_id'])
            || !is_int($m['observed_uptime_ms'] ?? null) || $m['observed_uptime_ms'] < 0
            || !is_string($m['observed_at'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $m['observed_at'])
            || ($event['clock']['synchronized'] ?? null) !== true
            || !is_numeric($event['clock']['estimated_error_ms'] ?? null)
            || $event['clock']['estimated_error_ms'] < 0 || $event['clock']['estimated_error_ms'] > 5000) return 'unusable';
        try { $at = CarbonImmutable::parse($m['observed_at'])->utc(); $captured = CarbonImmutable::parse($event['occurred_at'])->utc(); }
        catch (\Throwable $e) { return 'unusable'; }
        $ageMs = (int) round(((float) $captured->format('U.u') - (float) $at->format('U.u')) * 1000);
        if ($ageMs < 0 || $ageMs > 5000 || $m['observed_uptime_ms'] > ($event['uptime_ms'] ?? -1)) return 'unusable';
        }

        return DB::transaction(function () use ($inbox, $m, $at, $snapshot) {
            $installation = DB::table('device_installations')->where('organization_id', $inbox->organization_id)->where('id', $inbox->installation_id)->where('device_id', $inbox->device_id)->first();
            if (!$installation || $at->lt(CarbonImmutable::parse($installation->effective_from)) || ($installation->effective_to !== null && $at->gte(CarbonImmutable::parse($installation->effective_to)))) return 'unusable';
            $vehicle = DB::table('vehicles')->where('organization_id', $inbox->organization_id)->where('id', $installation->vehicle_id)->lockForUpdate()->first();
            if (!$vehicle) throw new \RuntimeException('Vehicle no longer exists.');
            $time = $at->format('Y-m-d H:i:s.uP');
            $speed = number_format((float) $m['value'], 3, '.', '');
            $rawSpeed = json_encode((float) $m['value'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $fingerprint = hash('sha256', implode('|', [$inbox->installation_id, $time, $m['observed_uptime_ms'], $rawSpeed]));
            $old = DB::table('mileage_speed_observations')->where('device_id', $inbox->device_id)->where('boot_id', $inbox->boot_id)->where('sample_id', $m['sample_id'])->first();
            if ($old) {
                if (!hash_equals($old->fingerprint, $fingerprint)) throw new \DomainException('Conflicting speed sample identity.');
                return 'duplicate';
            }
            $coincident = DB::table('mileage_speed_observations')->where('organization_id', $inbox->organization_id)->where('vehicle_id', $vehicle->id)->where('observed_at', $time)->exists();
            if ($coincident) throw new \DomainException('Ambiguous coincident speed observations.');
            $obsId = (string) Str::uuid();
            DB::table('mileage_speed_observations')->insert(['id'=>$obsId, 'organization_id'=>$inbox->organization_id, 'vehicle_id'=>$vehicle->id, 'installation_id'=>$inbox->installation_id, 'device_id'=>$inbox->device_id, 'boot_id'=>$inbox->boot_id, 'sample_id'=>$m['sample_id'], 'inbox_id'=>$inbox->id, 'observed_at'=>$time, 'observed_uptime_ms'=>$m['observed_uptime_ms'], 'speed_kmh'=>$speed, 'quality'=>'valid', 'source_kind'=>$snapshot ? 'packet_snapshot' : 'obd_sample', 'fingerprint'=>$fingerprint, 'created_at'=>now()]);
            $maxGapMs = app(\App\Services\Entitlements::class)->maxCompatibleGapSeconds($inbox->organization_id) * 1000;
            $this->connectNeighbors($inbox->organization_id, $vehicle->id, $obsId, $time, $maxGapMs);
            return 'stored';
        });
    }

    private function connectNeighbors(string $org, string $vehicle, string $id, string $time, int $maxGapMs): void
    {
        $query = fn () => DB::table('mileage_speed_observations')->where('organization_id', $org)->where('vehicle_id', $vehicle);
        $before=$query()->where('observed_at','<',$time)->orderByDesc('observed_at')->first();
        $after=$query()->where('observed_at','>',$time)->orderBy('observed_at')->first();
        $sample=$query()->where('id',$id)->first();
        if ($before && $after) DB::table('vehicle_distance_segments')->where('organization_id',$org)->where('vehicle_id',$vehicle)->where('start_observation_id',$before->id)->where('end_observation_id',$after->id)->whereNull('superseded_at')->update(['superseded_at'=>now()]);
        if ($before) $this->insertSegment($org,$vehicle,$before,$sample,$maxGapMs);
        if ($after) $this->insertSegment($org,$vehicle,$sample,$after,$maxGapMs);
    }

    private function insertSegment(string $org, string $vehicle, object $a, object $b, int $maxGapMs): void
    {
        $from=CarbonImmutable::parse($a->observed_at)->utc(); $to=CarbonImmutable::parse($b->observed_at)->utc();
        $wallMs=(int) round(((float) $to->format('U.u')-(float) $from->format('U.u'))*1000);
        $uptimeMs=(int) $b->observed_uptime_ms-(int) $a->observed_uptime_ms;
        $valid = $a->installation_id === $b->installation_id && $a->device_id === $b->device_id && $a->boot_id === $b->boot_id
            && $uptimeMs > 0 && $uptimeMs <= $maxGapMs && $wallMs > 0 && abs($wallMs-$uptimeMs) <= 1000;
        $snapshot = $a->source_kind === 'packet_snapshot' && $b->source_kind === 'packet_snapshot';
        if ($snapshot) $valid = $a->installation_id === $b->installation_id && $a->device_id === $b->device_id && $wallMs > 0 && $wallMs <= $maxGapMs;
        if ($a->source_kind !== $b->source_kind) $valid = false;
        $distance = $valid ? number_format((((float) $a->speed_kmh + (float) $b->speed_kmh) * ($snapshot ? $wallMs : $uptimeMs) / 7200), 6, '.', '') : null;
        DB::table('vehicle_distance_segments')->insert(['id'=>(string) Str::uuid(), 'organization_id'=>$org, 'vehicle_id'=>$vehicle, 'start_observation_id'=>$a->id, 'end_observation_id'=>$b->id, 'from_at'=>$from->format('Y-m-d H:i:s.uP'), 'to_at'=>$to->format('Y-m-d H:i:s.uP'), 'distance_m'=>$distance, 'source'=>$valid ? ($snapshot ? 'packet_speed' : 'obd_speed') : 'unknown', 'quality'=>$valid ? ($snapshot ? 'packet_snapshot_estimate' : 'estimated') : 'source_gap_or_clock_uncertain', 'algorithm'=>'speed-trapezoid-v1', 'created_at'=>now()]);
    }
}
