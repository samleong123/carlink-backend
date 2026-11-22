<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Internal domain service only: callers must first authenticate the device through
 * independently enforced broker ACLs. It is deliberately not exposed as HTTP.
 * Late/offline events are rejected for review, not reconciled as current shifts.
 */
class DriverShiftService
{
    public function __construct(private readonly DriverCredentials $credentials) {}

    /** Resolve the authoritative server-side driver shift covering a telemetry sample timestamp. */
    public function resolveShiftForTelemetry(string $organizationId, string $vehicleId, string $installationId, CarbonImmutable $at): ?string
    {
        return DB::table('driver_shifts')
            ->where('organization_id', $organizationId)
            ->where('vehicle_id', $vehicleId)
            ->where('device_installation_id', $installationId)
            ->where('started_at', '<=', $at)
            ->where('attribution_expires_at', '>', $at)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $at))
            ->orderByDesc('started_at')
            ->value('id');
    }

    public function acceptAuthenticatedEvent(string $organization, string $deviceId, array $event): array
    {
        foreach (['event_id', 'installation_id', 'local_session_id'] as $field) {
            if (!is_string($event[$field] ?? null) || !Str::isUuid($event[$field])) throw new \InvalidArgumentException('Invalid event identity.');
        }
        if (($event['credential_type'] ?? null) !== 'nfc_uid_sha256' || !is_string($event['credential_fingerprint'] ?? null) || !preg_match('/^[0-9a-f]{64}$/iD', $event['credential_fingerprint'])) throw new \InvalidArgumentException('Invalid credential fingerprint.');
        $action = $event['action'] ?? 'authenticate';
        if (!is_string($action) || !in_array($action, ['authenticate', 'toggle', 'tap_in', 'tap_out'], true)) throw new \InvalidArgumentException('Invalid auth action.');
        if (!is_string($event['occurred_at'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $event['occurred_at'])) throw new \InvalidArgumentException('Invalid event time.');
        try { $occurred = CarbonImmutable::parse($event['occurred_at'])->utc(); } catch (\Throwable $e) { throw new \InvalidArgumentException('Invalid event time.', previous: $e); }
        $fingerprintHmac = $this->credentials->hmac($organization, $event['credential_fingerprint']);
        $requestHmac = hash_hmac('sha256', implode('|', array_merge([$organization, $deviceId, $event['event_id'], $event['installation_id'], $event['local_session_id'], $occurred->format('Y-m-d\TH:i:s.uP'), $fingerprintHmac], $action !== 'authenticate' ? [$action] : [])), config('nfc.hmac_key'));

        return DB::transaction(function () use ($organization, $deviceId, $event, $action, $occurred, $fingerprintHmac, $requestHmac) {
            // A tenant row lock serializes shift transitions for both driver and vehicle.
            $org = DB::table('organizations')->where('id', $organization)->where('status', 'active')->lockForUpdate()->first();
            if (!$org) throw new \InvalidArgumentException('Unknown organization.');
            $keyConfig = DB::table('nfc_key_configs')->where('organization_id', $organization)->first();
            if ($keyConfig && ($keyConfig->key_version !== (string) config('nfc.hmac_key_version') || !hash_equals($keyConfig->key_check, $this->credentials->keyCheck($organization)))) throw new \RuntimeException('NFC key rotation requires migration.');
            $device = DB::table('devices')->where('organization_id', $organization)->where('id', $deviceId)->where('status', 'active')->first();
            if (!$device || !$device->broker_principal) throw new \InvalidArgumentException('Unknown device.');
            $prior = DB::table('driver_auth_events')->where('device_id', $deviceId)->where('event_id', $event['event_id'])->first();
            if ($prior) {
                if (!hash_equals($prior->request_hmac, $requestHmac)) throw new \InvalidArgumentException('Conflicting event identity.');
                return ['decision' => $prior->decision, 'shift_id' => $prior->shift_id];
            }
            $installation = DB::table('device_installations')->where('organization_id', $organization)->where('device_id', $deviceId)->where('id', $event['installation_id'])->where('effective_from', '<=', $occurred)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $occurred))->first();
            $credential = DB::table('driver_credentials')->where('organization_id', $organization)->where('credential_hmac', $fingerprintHmac)->where('hash_key_version', (string) config('nfc.hmac_key_version'))->whereNull('revoked_at')->where('issued_at', '<=', $occurred)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $occurred))->first();
            $decision = 'requires_review'; $shiftId = null;
            if (!$installation || ($installation->effective_to && CarbonImmutable::parse($installation->effective_to)->lte(now())) || $occurred->gt(now()->addMinutes(2)) || $occurred->lt(now()->subMinutes(5))) {
                $decision = 'requires_review';
            } elseif (!$credential) {
                $decision = 'rejected_unknown_credential';
            } else {
                $driver = DB::table('drivers')->where('organization_id', $organization)->where('id', $credential->driver_id)->first();
                $vehicle = DB::table('vehicles')->where('organization_id', $organization)->where('id', $installation->vehicle_id)->first();
                if (!$driver || $driver->status !== 'active' || !$vehicle || $vehicle->status !== 'active') {
                    $decision = 'rejected_driver_inactive';
                } else {
                    // An installation scheduled to end in the future bounds driver
                    // attribution even if the configured shift lifetime is longer.
                    $attributionExpiry = $occurred->addHours(max(1, config('nfc.shift_expiry_hours')));
                    if ($installation->effective_to) $attributionExpiry = $attributionExpiry->min(CarbonImmutable::parse($installation->effective_to));
                    // A delayed event cannot create an interval overlapping a closed shift.
                    $historicalConflict = DB::table('driver_shifts')->where('organization_id', $organization)->where('state', 'closed')->where('ended_at', '>', $occurred)->where(fn ($q) => $q->where('driver_id', $driver->id)->orWhere('vehicle_id', $vehicle->id))->exists();
                    if ($historicalConflict) {
                        $decision = 'requires_review';
                    } else {
                    // Expire relevant stale shifts before checking conflicts, including when the scheduler is late.
                    $relevant = DB::table('driver_shifts')->where('organization_id', $organization)->where('state', 'active')->where(fn ($q) => $q->where('driver_id', $driver->id)->orWhere('vehicle_id', $vehicle->id))->get();
                    foreach ($relevant as $existing) {
                        if (CarbonImmutable::parse($existing->attribution_expires_at)->lte($occurred)) {
                            DB::table('driver_shifts')->where('id', $existing->id)->update(['state' => 'closed', 'ended_at' => $existing->attribution_expires_at, 'closure_reason' => 'attribution_expired', 'version' => $existing->version + 1, 'updated_at' => now()]);
                        }
                    }
                    $sameDriver = DB::table('driver_shifts')->where('organization_id', $organization)->where('driver_id', $driver->id)->where('state', 'active')->first();
                    $current = DB::table('driver_shifts')->where('organization_id', $organization)->where('vehicle_id', $vehicle->id)->where('state', 'active')->first();
                    if (($sameDriver && $sameDriver->vehicle_id !== $vehicle->id) || ($current && CarbonImmutable::parse($current->last_authenticated_at)->gt($occurred))) {
                        $decision = 'rejected_conflicting_shift';
                    } elseif ($current && $current->driver_id === $driver->id) {
                        $shiftId = $current->id;
                        if (in_array($action, ['toggle', 'tap_out'], true)) {
                            $endedAt = $occurred->max(CarbonImmutable::parse($current->started_at));
                            DB::table('driver_shifts')->where('id', $shiftId)->update([
                                'state' => 'closed',
                                'ended_at' => $endedAt,
                                'closure_reason' => 'driver_tap_out',
                                'version' => $current->version + 1,
                                'updated_at' => now(),
                            ]);
                            $this->closeActiveJourney($organization, $vehicle->id, $occurred, 'driver_signed_out');
                            $decision = 'signed_out';
                        } else {
                            // Out-of-order events were rejected above; same card reauthentication extends attribution.
                            if ($current->credential_id !== $credential->id || CarbonImmutable::parse($current->last_authenticated_at)->addSeconds(max(0, config('nfc.debounce_seconds')))->lte($occurred)) {
                                DB::table('driver_shifts')->where('id', $shiftId)->update(['credential_id' => $credential->id, 'last_authenticated_at' => $occurred, 'attribution_expires_at' => $attributionExpiry, 'version' => $current->version + 1, 'updated_at' => now()]);
                            }
                            $decision = 'accepted';
                        }
                    } elseif ($action === 'tap_out') {
                        $decision = 'rejected_no_active_shift';
                    } else {
                        if ($current) DB::table('driver_shifts')->where('id', $current->id)->update(['state' => 'closed', 'ended_at' => $occurred, 'closure_reason' => 'handover', 'version' => $current->version + 1, 'updated_at' => now()]);
                        $shiftId = (string) Str::uuid();
                        DB::table('driver_shifts')->insert(['id' => $shiftId, 'organization_id' => $organization, 'driver_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'credential_id' => $credential->id, 'device_installation_id' => $installation->id, 'started_at' => $occurred, 'last_authenticated_at' => $occurred, 'attribution_expires_at' => $attributionExpiry, 'state' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                        $decision = 'accepted';
                        if (!$current) $this->splitActiveAutomaticJourney($organization, $vehicle->id, $occurred);
                    }
                    }
                }
            }
            DB::table('driver_auth_events')->insert(['id' => (string) Str::uuid(), 'organization_id' => $organization, 'device_id' => $deviceId, 'event_id' => $event['event_id'], 'local_session_id' => $event['local_session_id'], 'credential_id' => $credential->id ?? null, 'shift_id' => $shiftId, 'occurred_at' => $occurred, 'decision' => $decision, 'request_hmac' => $requestHmac, 'created_at' => now(), 'updated_at' => now()]);
            return ['decision' => $decision, 'shift_id' => $shiftId];
        });
    }

    /** Close any active journey while preserving last telemetry observation bounds so JourneyDetector stays monotonic. */
    private function closeActiveJourney(string $organization, string $vehicleId, CarbonImmutable $occurred, string $reason): ?array
    {
        // Lock vehicles after organizations to match JourneyDetector's lock order.
        DB::table('vehicles')->where('organization_id', $organization)->where('id', $vehicleId)->lockForUpdate()->first();
        $jState = DB::table('journey_detection_states')->where('organization_id', $organization)->where('vehicle_id', $vehicleId)->first();
        if (!$jState || !$jState->current_journey_id) return null;
        $activeJourney = DB::table('journeys')->where('organization_id', $organization)->where('id', $jState->current_journey_id)->where('state', 'active')->first();
        if (!$activeJourney) return null;
        $now = now();
        $obsId = $jState->last_observation_id ?? $activeJourney->start_observation_id;
        $endedAt = CarbonImmutable::parse($activeJourney->last_observed_at)->utc()->max($occurred);
        DB::table('journeys')->where('organization_id', $organization)->where('id', $activeJourney->id)->where('state', 'active')->update([
            'state' => 'completed',
            'ended_at' => $endedAt,
            'end_observation_id' => $obsId,
            'end_reason' => $reason,
            'revision' => $activeJourney->revision + 1,
            'updated_at' => $now,
        ]);
        // Keep last_observed_at and last_observation_id at the last real telemetry observation so JourneyDetector does not trigger requires_rebuild.
        DB::table('journey_detection_states')->where('id', $jState->id)->update([
            'state' => 'stationary',
            'current_journey_id' => null,
            'candidate_started_at' => null,
            'candidate_observation_id' => null,
            'stop_mode' => null,
            'updated_at' => $now,
        ]);
        return ['state_id' => $jState->id, 'obs_id' => $obsId];
    }

    /** Complete any active unattributed automatic journey and start an active journey for the new driver session. */
    private function splitActiveAutomaticJourney(string $organization, string $vehicleId, CarbonImmutable $occurred): void
    {
        $closed = $this->closeActiveJourney($organization, $vehicleId, $occurred, 'driver_authenticated');
        if (!$closed) return;
        $now = now();
        $newJourneyId = (string) Str::uuid();
        DB::table('journeys')->insert([
            'id' => $newJourneyId,
            'organization_id' => $organization,
            'vehicle_id' => $vehicleId,
            'state' => 'active',
            'started_at' => $occurred,
            'last_observed_at' => $occurred,
            'start_observation_id' => $closed['obs_id'],
            'unknown_seconds' => 0,
            'detection_version' => 'driver-session-v1',
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('journey_detection_states')->where('id', $closed['state_id'])->update([
            'state' => 'active',
            'current_journey_id' => $newJourneyId,
            'candidate_started_at' => null,
            'candidate_observation_id' => null,
            'stop_mode' => null,
            'updated_at' => $now,
        ]);
    }
}
