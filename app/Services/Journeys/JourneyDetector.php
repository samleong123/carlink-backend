<?php

namespace App\Services\Journeys;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Pilot detector fed only by accepted, validated speed observations. */
class JourneyDetector
{
    public function project(string $observationId): string
    {
        $observation = DB::table('mileage_speed_observations')->where('id', $observationId)->first();
        if (!$observation) throw new \InvalidArgumentException('Unknown accepted observation.');

        return DB::transaction(function () use ($observation) {
            $vehicle = DB::table('vehicles')->where('organization_id', $observation->organization_id)
                ->where('id', $observation->vehicle_id)->lockForUpdate()->first();
            if (!$vehicle) throw new \RuntimeException('Vehicle no longer exists.');
            $previous = DB::table('journey_processed_observations')->where('observation_id', $observation->id)->first();
            if ($previous) return 'duplicate';

            $org = $observation->organization_id;
            $vehicleId = $observation->vehicle_id;
            $state = DB::table('journey_detection_states')->where('organization_id', $org)->where('vehicle_id', $vehicleId)->first();
            if (!$state) {
                DB::table('journey_detection_states')->insert(['id' => (string) Str::uuid(), 'organization_id' => $org, 'vehicle_id' => $vehicleId, 'state' => 'stationary', 'created_at' => now(), 'updated_at' => now()]);
                $state = DB::table('journey_detection_states')->where('organization_id', $org)->where('vehicle_id', $vehicleId)->first();
            }
            $time = CarbonImmutable::parse($observation->observed_at)->utc();
            $last = $state->last_observed_at ? CarbonImmutable::parse($state->last_observed_at)->utc() : null;
            if (($last && $time->lte($last)) || ($state->rebuild_before && $time->lte(CarbonImmutable::parse($state->rebuild_before)->utc()))) {
                $this->record($observation, 'requires_rebuild');
                return 'requires_rebuild';
            }
            $mode = $state->state;
            $current = $state->current_journey_id;
            $candidateAt = $state->candidate_started_at ? CarbonImmutable::parse($state->candidate_started_at)->utc() : null;
            $candidateId = $state->candidate_observation_id;
            $stopMode = $state->stop_mode;
            $gapMs = $last ? (int) round(((float) $time->format('U.u') - (float) $last->format('U.u')) * 1000) : 0;
            $maxGapMs = app(\App\Services\Entitlements::class)->maxCompatibleGapSeconds($org) * 1000;
            $uptimeGap = $state->last_uptime_ms === null ? null : (int) $observation->observed_uptime_ms - (int) $state->last_uptime_ms;
            $continuous = !$last || ($gapMs <= $maxGapMs && $uptimeGap !== null && $uptimeGap > 0 && abs($gapMs - $uptimeGap) <= 1000
                && $state->last_device_id === $observation->device_id && $state->last_installation_id === $observation->installation_id && $state->last_boot_id === $observation->boot_id);
            if ($observation->source_kind === 'packet_snapshot' && $last) {
                $previousKind = DB::table('mileage_speed_observations')->where('id',$state->last_observation_id)->value('source_kind');
                $continuous = $previousKind === 'packet_snapshot' && $gapMs > 0 && $gapMs <= $maxGapMs
                    && $state->last_device_id === $observation->device_id && $state->last_installation_id === $observation->installation_id;
            }

            if ($last && $gapMs >= 3600000 && $current) {
                DB::table('journeys')->where('organization_id', $org)->where('id', $current)->where('state', 'active')->update([
                    'state' => 'incomplete', 'ended_at' => $last, 'end_observation_id' => $state->last_observation_id,
                    'end_reason' => 'automatic_end', 'updated_at' => now(),
                ]);
                $mode = 'stationary'; $current = null; $candidateAt = null; $candidateId = null; $stopMode = null;
            } elseif (!$continuous) {
                if ($current) {
                    DB::table('journeys')->where('organization_id', $org)->where('id', $current)->increment('unknown_seconds', (int) ceil($gapMs / 1000));
                    $mode = 'active';
                } else $mode = 'stationary';
                $candidateAt = null; $candidateId = null; $stopMode = null;
            }

            $speed = (float) $observation->speed_kmh;
            $engine = $this->engineState($observation->inbox_id, $time);
            if (!$current) {
                if ($speed > 5) {
                    if ($mode !== 'candidate_moving' || !$continuous) {
                        $mode = 'candidate_moving'; $candidateAt = $time; $candidateId = $observation->id;
                    } elseif ($candidateAt && $this->elapsedMs($candidateAt, $time) >= 10000) {
                        $current = (string) Str::uuid();
                        DB::table('journeys')->insert(['id' => $current, 'organization_id' => $org, 'vehicle_id' => $vehicleId,
                            'state' => 'active', 'started_at' => $candidateAt, 'last_observed_at' => $time,
                             'start_observation_id' => $candidateId, 'unknown_seconds' => 0, 'detection_version' => $observation->source_kind === 'packet_snapshot' ? 'packet-snapshot-v1' : 'pilot-v1',
                            'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
                        $mode = 'active'; $candidateAt = null; $candidateId = null;
                    }
                } else { $mode = 'stationary'; $candidateAt = null; $candidateId = null; }
            } else {
                DB::table('journeys')->where('organization_id', $org)->where('id', $current)->where('state', 'active')->update(['last_observed_at' => $time, 'updated_at' => now()]);
                if ($speed < 2) {
                    $newStopMode = $engine === 'off' ? 'off' : ($engine === 'running' ? 'running' : 'unknown');
                    if ($mode !== 'candidate_stopped' || !$continuous || $stopMode !== $newStopMode) {
                        $mode = 'candidate_stopped'; $candidateAt = $time; $candidateId = $observation->id; $stopMode = $newStopMode;
                    } elseif ($candidateAt && $stopMode !== 'running' && $this->elapsedMs($candidateAt, $time) >= ($stopMode === 'off' ? 300000 : 900000)) {
                        DB::table('journeys')->where('organization_id', $org)->where('id', $current)->where('state', 'active')->update([
                            'state' => 'completed', 'ended_at' => $time, 'end_observation_id' => $observation->id,
                            'end_reason' => $stopMode === 'off' ? 'stationary_engine_off' : 'stationary_engine_unknown', 'updated_at' => now(),
                        ]);
                        $mode = 'stationary'; $current = null; $candidateAt = null; $candidateId = null; $stopMode = null;
                    }
                } else { $mode = 'active'; $candidateAt = null; $candidateId = null; $stopMode = null; }
            }
            DB::table('journey_detection_states')->where('id', $state->id)->update([
                'state' => $mode, 'current_journey_id' => $current, 'last_observation_id' => $observation->id,
                'last_observed_at' => $time, 'last_device_id' => $observation->device_id, 'last_installation_id' => $observation->installation_id,
                'last_boot_id' => $observation->boot_id, 'last_uptime_ms' => $observation->observed_uptime_ms,
                'candidate_started_at' => $candidateAt, 'candidate_observation_id' => $candidateId, 'stop_mode' => $stopMode, 'updated_at' => now(),
            ]);
            $this->record($observation, 'processed');
            return 'processed';
        });
    }

    private function record(object $observation, string $outcome): void
    {
        DB::table('journey_processed_observations')->insert(['observation_id' => $observation->id, 'organization_id' => $observation->organization_id,
            'vehicle_id' => $observation->vehicle_id, 'outcome' => $outcome, 'processed_at' => now()]);
    }

    private function elapsedMs(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return (int) round(((float) $end->format('U.u') - (float) $start->format('U.u')) * 1000);
    }

    private function engineState(string $inboxId, CarbonImmutable $sampleTime): string
    {
        $inbox = DB::table('telemetry_inbox')->where('id', $inboxId)->first(['raw_payload','source_format']);
        try { $event = json_decode((string) $inbox?->raw_payload, true, 32, JSON_THROW_ON_ERROR); } catch (\Throwable $e) { return 'unknown'; }
        if ($inbox->source_format === 'pi_snapshot_v1') {
            $running = $event['vehicle']['engine_running'] ?? null;
            return is_bool($running) ? ($running ? 'running' : 'off') : 'unknown';
        }
        $engine = $event['payload']['vehicle']['engine_state'] ?? null;
        if (!is_array($engine) || ($engine['quality'] ?? null) !== 'valid' || !in_array($engine['value'] ?? null, ['off', 'running'], true) || !is_string($engine['observed_at'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $engine['observed_at'])) return 'unknown';
        try { $observed = CarbonImmutable::parse($engine['observed_at'])->utc(); } catch (\Throwable $e) { return 'unknown'; }
        $age = (float) $sampleTime->format('U.u') - (float) $observed->format('U.u');
        return $age >= 0 && $age <= 5 ? $engine['value'] : 'unknown';
    }
}
