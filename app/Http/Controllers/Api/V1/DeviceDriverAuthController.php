<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\DriverShiftService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Edge-authenticated NFC driver authentication endpoint; never trusts client installation_id or driver_id. */
class DeviceDriverAuthController
{
    public function authenticate(Request $r, string $device, DriverShiftService $shifts)
    {
        $v = $r->validate([
            'event_id' => ['required', 'uuid'],
            'local_session_id' => ['required', 'uuid'],
            'credential_type' => ['required', 'in:nfc_uid_sha256'],
            'credential_fingerprint' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/iD'],
            'occurred_at' => ['required', 'string'],
            'action' => ['sometimes', 'string', 'in:authenticate,toggle,tap_in,tap_out'],
            'installation_id' => ['prohibited'],
            'driver_id' => ['prohibited'],
            'organization_id' => ['prohibited'],
            'codes' => ['prohibited'],
        ]);
        abort_if(array_diff(array_keys($r->all()), array_keys($v)), 422);

        $d = $r->attributes->get('authenticated_device');
        $installation = DB::table('device_installations')
            ->where('organization_id', $d->organization_id)
            ->where('device_id', $d->id)
            ->whereNull('effective_to')
            ->where('effective_from', '<=', now())
            ->first();
        if (!$installation) {
            return ApiResponse::error($r, 'No Active Installation', 'Device does not have an active vehicle installation.', 409);
        }

        try {
            $decision = $shifts->acceptAuthenticatedEvent($d->organization_id, $d->id, [
                'event_id' => $v['event_id'],
                'installation_id' => $installation->id,
                'local_session_id' => $v['local_session_id'],
                'credential_type' => $v['credential_type'],
                'credential_fingerprint' => $v['credential_fingerprint'],
                'occurred_at' => $v['occurred_at'],
                'action' => $v['action'] ?? 'authenticate',
            ]);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($r, 'Invalid Driver Authentication Event', $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($r, 'NFC Unavailable', 'NFC credential configuration is unavailable.', 503);
        }

        $shift = null;
        if ($decision['shift_id']) {
            $shift = DB::table('driver_shifts')
                ->join('drivers', function ($join) {
                    $join->on('driver_shifts.driver_id', '=', 'drivers.id')
                        ->on('driver_shifts.organization_id', '=', 'drivers.organization_id');
                })
                ->where('driver_shifts.organization_id', $d->organization_id)
                ->where('driver_shifts.id', $decision['shift_id'])
                ->select('driver_shifts.driver_id', 'drivers.name', 'driver_shifts.attribution_expires_at', 'driver_shifts.state')
                ->first();
        }

        $activeJourneyId = DB::table('journey_detection_states')
            ->where('organization_id', $d->organization_id)
            ->where('vehicle_id', $installation->vehicle_id)
            ->value('current_journey_id');

        return ApiResponse::success($r, [
            'decision' => $decision['decision'],
            'shift_id' => $decision['shift_id'],
            'local_session_id' => $v['local_session_id'],
            'driver_id' => $shift?->driver_id,
            'driver_display_name' => $shift?->name,
            'valid_until' => ($shift && $shift->state === 'active') ? CarbonImmutable::parse($shift->attribution_expires_at)->utc()->toIso8601String() : null,
            'active_journey_id' => $activeJourneyId,
        ], 'Driver Authentication Processed');
    }
}
