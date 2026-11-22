<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\DriverCredentials;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DriverShiftController
{
    private function manager(Request $request): void
    {
        $action = $request->route()?->getActionName() ?? '';
        abort_unless(app(\App\Services\Permissions::class)->allows($request->attributes->get('membership'), str_ends_with($action, '@close') ? 'shifts.manage' : 'credentials.manage'), 403);
    }

    public function credentials(Request $request, string $organization, string $driver)
    {
        $this->manager($request);
        $driverRow = DB::table('drivers')->where('organization_id', $organization)->where('id', $driver)->first();
        abort_unless($driverRow, 404);
        $items = DB::table('driver_credentials')
            ->where('organization_id', $organization)
            ->where('driver_id', $driver)
            ->orderByDesc('created_at')
            ->get(['id', 'driver_id', 'type', 'assurance', 'issued_at', 'expires_at', 'revoked_at', 'created_at']);
        return ApiResponse::success($request, ['items' => $items], 'Driver Credentials Retrieved');
    }

    public function enrol(Request $request, string $organization, string $driver, DriverCredentials $credentials)
    {
        $this->manager($request);
        $data = $request->validate(['credential_fingerprint' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/iD'], 'credential_type' => ['required', 'in:nfc_uid_sha256'], 'expires_at' => ['nullable', 'date', 'after:now']]);
        try { $hash = $credentials->hmac($organization, $data['credential_fingerprint']); } catch (\RuntimeException $e) { return ApiResponse::error($request, 'NFC Unavailable', 'NFC credential configuration is incomplete.', 503); }
        $result = DB::transaction(function () use ($organization, $driver, $data, $hash, $request, $credentials) {
            DB::table('organizations')->where('id', $organization)->lockForUpdate()->first();
            $driverRow = DB::table('drivers')->where('organization_id', $organization)->where('id', $driver)->first();
            abort_unless($driverRow, 404);
            abort_unless($driverRow->status === 'active', 409);
            // Rotation requires an explicit multi-key migration; detect a replaced key even if the version is unchanged.
            $keyConfig = DB::table('nfc_key_configs')->where('organization_id', $organization)->first();
            $keyCheck = $credentials->keyCheck($organization);
            if ($keyConfig && ($keyConfig->key_version !== (string) config('nfc.hmac_key_version') || !hash_equals($keyConfig->key_check, $keyCheck))) return null;
            if (DB::table('driver_credentials')->where('organization_id', $organization)->whereNull('revoked_at')->where('hash_key_version', '<>', (string) config('nfc.hmac_key_version'))->exists()) return null;
            if (DB::table('driver_credentials')->where('organization_id', $organization)->where('credential_hmac', $hash)->whereNull('revoked_at')->exists()) return false;
            if (!$keyConfig) DB::table('nfc_key_configs')->insert(['organization_id' => $organization, 'key_version' => (string) config('nfc.hmac_key_version'), 'key_check' => $keyCheck]);
            $id = (string) Str::uuid();
            DB::table('driver_credentials')->insert(['id' => $id, 'organization_id' => $organization, 'driver_id' => $driver, 'credential_hmac' => $hash, 'hash_key_version' => (string) config('nfc.hmac_key_version'), 'type' => 'nfc_uid_sha256', 'assurance' => 'uid_low', 'issued_at' => now(), 'expires_at' => $data['expires_at'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('driver_credential_events')->insert(['id' => (string) Str::uuid(), 'organization_id' => $organization, 'credential_id' => $id, 'actor_user_id' => $request->user()->id, 'event' => 'enrolled', 'created_at' => now()]);
            return $id;
        });
        if ($result === null) return ApiResponse::error($request, 'NFC Unavailable', 'Credential key rotation requires migration.', 503);
        if ($result === false) return ApiResponse::error($request, 'Credential Exists', 'This credential is already active.', 409);
        return ApiResponse::success($request, ['credential' => ['id' => $result, 'driver_id' => $driver, 'type' => 'nfc_uid_sha256', 'assurance' => 'uid_low']], 'Credential Enrolled', 'Credential enrolled.', 201);
    }

    public function revoke(Request $request, string $organization, string $driver, string $credential)
    {
        $this->manager($request);
        DB::transaction(function () use ($organization, $driver, $credential, $request) {
            DB::table('organizations')->where('id', $organization)->lockForUpdate()->first();
            $row = DB::table('driver_credentials')->where('organization_id', $organization)->where('driver_id', $driver)->where('id', $credential)->first();
            abort_unless($row, 404);
            if (!$row->revoked_at) {
                DB::table('driver_credentials')->where('id', $credential)->update(['revoked_at' => now(), 'updated_at' => now()]);
                DB::table('driver_credential_events')->insert(['id' => (string) Str::uuid(), 'organization_id' => $organization, 'credential_id' => $credential, 'actor_user_id' => $request->user()->id, 'event' => 'revoked', 'created_at' => now()]);
                $shifts = DB::table('driver_shifts')->where('organization_id', $organization)->where('credential_id', $credential)->where('state', 'active')->get();
                foreach ($shifts as $shift) {
                    $end = CarbonImmutable::parse($shift->attribution_expires_at)->min(now())->max(CarbonImmutable::parse($shift->last_authenticated_at));
                    DB::table('driver_shifts')->where('id', $shift->id)->update(['state' => 'closed', 'ended_at' => $end, 'closure_reason' => 'credential_revoked', 'version' => $shift->version + 1, 'updated_at' => now()]);
                    DB::table('driver_shift_events')->insert(['id' => (string) Str::uuid(), 'organization_id' => $organization, 'shift_id' => $shift->id, 'actor_user_id' => $request->user()->id, 'event' => 'credential_revoked', 'reason' => 'Credential revoked', 'created_at' => now()]);
                }
            }
        });
        return ApiResponse::success($request, ['credential' => ['id' => $credential, 'revoked' => true]], 'Credential Revoked');
    }

    private function shiftBaseQuery(Request $request, string $organization)
    {
        $membership = $request->attributes->get('membership');
        $permissions = app(\App\Services\Permissions::class);
        $query = DB::table('driver_shifts')
            ->leftJoin('drivers as d', fn ($j) => $j->on('d.id', '=', 'driver_shifts.driver_id')->on('d.organization_id', '=', 'driver_shifts.organization_id'))
            ->leftJoin('vehicles as v', fn ($j) => $j->on('v.id', '=', 'driver_shifts.vehicle_id')->on('v.organization_id', '=', 'driver_shifts.organization_id'))
            ->where('driver_shifts.organization_id', $organization)
            ->select([
                'driver_shifts.*',
                'd.name as driver_name',
                'v.registration_number',
                'v.make as vehicle_make',
                'v.model as vehicle_model',
            ]);
        if (config('saas.enforce_subscriptions')) {
            $query->where(fn ($q) => $q->whereNull('driver_shifts.ended_at')->orWhere('driver_shifts.ended_at', '>=', app(\App\Services\Entitlements::class)->cutoff($organization)));
        }
        if (!$permissions->allows($membership, 'shifts.read') && $permissions->allows($membership, 'shifts.own')) {
            $query->whereIn('driver_shifts.driver_id', DB::table('drivers')->select('id')->where('organization_id', $organization)->where('user_id', $request->user()->id));
        } else {
            abort_unless($permissions->allows($membership, 'shifts.read'), 403);
        }
        return $query;
    }

    public function index(Request $request, string $organization)
    {
        $items = $this->shiftBaseQuery($request, $organization)->orderByDesc('driver_shifts.started_at')->limit(100)->get();
        return ApiResponse::success($request, ['shifts' => $items], 'Driver Shifts Retrieved');
    }

    public function show(Request $request, string $organization, string $shift)
    {
        $row = $this->shiftBaseQuery($request, $organization)->where('driver_shifts.id', $shift)->first();
        abort_unless($row, 404);
        return ApiResponse::success($request, ['shift' => $row], 'Driver Shift Retrieved');
    }

    public function close(Request $request, string $organization, string $shift)
    {
        $this->manager($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        DB::transaction(function () use ($organization, $shift, $data, $request) {
            DB::table('organizations')->where('id', $organization)->lockForUpdate()->first();
            $row = DB::table('driver_shifts')->where('organization_id', $organization)->where('id', $shift)->first();
            abort_unless($row, 404); abort_unless($row->state === 'active', 409);
            $end = CarbonImmutable::parse($row->attribution_expires_at)->min(now())->max(CarbonImmutable::parse($row->last_authenticated_at));
            DB::table('driver_shifts')->where('id', $shift)->update(['state' => 'closed', 'ended_at' => $end, 'closure_reason' => 'administrative', 'version' => $row->version + 1, 'updated_at' => now()]);
            DB::table('driver_shift_events')->insert(['id' => (string) Str::uuid(), 'organization_id' => $organization, 'shift_id' => $shift, 'actor_user_id' => $request->user()->id, 'event' => 'administrative_close', 'reason' => $data['reason'], 'created_at' => now()]);
        });
        return ApiResponse::success($request, ['shift_id' => $shift], 'Driver Shift Closed');
    }
}
