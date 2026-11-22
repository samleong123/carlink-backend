<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Permissions;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Tenant-scoped registry only. Device provisioning and broker access are separate operations. */
class FleetController
{
    private const TABLES = ['vehicles', 'drivers', 'devices'];

    private function organization(Request $request): string
    {
        return (string) $request->attributes->get('membership')->organization_id;
    }

    private function authorizeFleet(Request $request, bool $write = false): void
    {
        $membership = $request->attributes->get('membership');
        $resource = $request->route('resource') ?? ($request->route('vehicle') && !$request->route('device') ? 'vehicles' : 'devices');
        $permission = $resource.'.'.($write ? 'write' : 'read');
        if (config('saas.managed_devices') && $write && $resource === 'devices') abort(403,'Device changes are reserved for SaaS staff.');
        abort_unless(app(\App\Services\Permissions::class)->allows($membership, $permission), 403);
    }

    private function table(string $resource): string
    {
        abort_unless(in_array($resource, self::TABLES, true), 404);
        return $resource;
    }

    private function rules(string $resource, bool $updating): array
    {
        $required = $updating ? 'sometimes' : 'required';
        return match ($resource) {
            'vehicles' => [
                'registration_number' => [$required, 'string', 'max:32', 'regex:/^[A-Za-z0-9 -]+$/'],
                'registration_country' => [$required, 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
                'make' => ['sometimes', 'nullable', 'string', 'max:100'],
                'model' => ['sometimes', 'nullable', 'string', 'max:100'],
                'model_year' => ['sometimes', 'nullable', 'integer', 'between:1886,2200'],
                'fuel_type' => ['sometimes', 'nullable', 'string', 'max:30'],
                'passenger_capacity' => ['sometimes', 'nullable', 'integer', 'between:0,65535'],
                'status' => ['sometimes', Rule::in(['active', 'maintenance', 'inactive'])],
            ],
            'drivers' => [
                'name' => [$required, 'string', 'max:160'],
                'employee_code' => ['sometimes', 'nullable', 'string', 'max:100'],
                'email' => ['sometimes', 'nullable', 'email', 'max:255'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
                'user_id' => ['sometimes','nullable','uuid'],
                'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            ],
            'devices' => [
                'serial_number' => [$required, 'string', 'max:160'],
                'name' => ['sometimes', 'nullable', 'string', 'max:160'],
                'app_version' => ['sometimes', 'nullable', 'string', 'max:100'],
            ],
        };
    }

    private function normalize(array $data): array
    {
        if (isset($data['registration_number'])) {
            $data['registration_normalized'] = strtoupper(preg_replace('/[ -]/', '', $data['registration_number']));
            abort_if($data['registration_normalized'] === '', 422, 'Registration number must contain letters or digits.');
        }
        if (isset($data['registration_country'])) {
            $data['registration_country'] = strtoupper($data['registration_country']);
        }
        return $data;
    }

    private function record(Request $request, string $table, string $id)
    {
        $row=DB::table($table)->where('organization_id', $this->organization($request))->where('id', $id)->first($table === 'devices' ? \App\Services\ManagedDevices::PUBLIC_FIELDS : ['*']) ?? abort(404);
        if ($table==='drivers') unset($row->photo_path);
        return $row;
    }

    private function canSeeActiveShiftTarget(Request $request, string $fallbackPermission): bool
    {
        $membership = $request->attributes->get('membership');
        $permissions = app(Permissions::class);
        return $permissions->allows($membership, 'shifts.read') || $permissions->allows($membership, $fallbackPermission);
    }

    private function activeShiftsByVehicle(string $org, array $vehicleIds): Collection
    {
        if ($vehicleIds === []) return collect();
        return DB::table('driver_shifts as sh')
            ->join('drivers as d', fn ($j) => $j->on('d.id', '=', 'sh.driver_id')->on('d.organization_id', '=', 'sh.organization_id'))
            ->where('sh.organization_id', $org)
            ->whereIn('sh.vehicle_id', $vehicleIds)
            ->where('sh.state', 'active')
            ->whereNull('sh.ended_at')
            ->where('sh.attribution_expires_at', '>', now())
            ->select([
                'sh.id',
                'sh.driver_id',
                'd.name as driver_name',
                'sh.vehicle_id',
                'sh.started_at',
                'sh.last_authenticated_at',
                'sh.attribution_expires_at',
                'sh.state',
            ])
            ->get()
            ->keyBy('vehicle_id');
    }

    private function activeShiftsByDriver(string $org, array $driverIds): Collection
    {
        if ($driverIds === []) return collect();
        return DB::table('driver_shifts as sh')
            ->join('vehicles as v', fn ($j) => $j->on('v.id', '=', 'sh.vehicle_id')->on('v.organization_id', '=', 'sh.organization_id'))
            ->where('sh.organization_id', $org)
            ->whereIn('sh.driver_id', $driverIds)
            ->where('sh.state', 'active')
            ->whereNull('sh.ended_at')
            ->where('sh.attribution_expires_at', '>', now())
            ->select([
                'sh.id',
                'sh.driver_id',
                'sh.vehicle_id',
                'v.registration_number',
                'v.make',
                'v.model',
                'sh.started_at',
                'sh.last_authenticated_at',
                'sh.attribution_expires_at',
                'sh.state',
            ])
            ->get()
            ->keyBy('driver_id');
    }

    private function attachVehicleShiftSummary(object $item, ?object $shift): void
    {
        $item->current_shift_id = $shift->id ?? null;
        $item->current_driver_id = $shift->driver_id ?? null;
        $item->current_driver_name = $shift->driver_name ?? null;
        $item->current_shift_started_at = $shift->started_at ?? null;
        $item->current_shift_expires_at = $shift->attribution_expires_at ?? null;
    }

    private function attachDriverShiftSummary(object $item, ?object $shift): void
    {
        $item->current_shift_id = $shift->id ?? null;
        $item->current_vehicle_id = $shift->vehicle_id ?? null;
        $item->current_vehicle_registration = $shift->registration_number ?? null;
        $item->current_vehicle_make = $shift->make ?? null;
        $item->current_vehicle_model = $shift->model ?? null;
        $item->current_shift_started_at = $shift->started_at ?? null;
        $item->current_shift_expires_at = $shift->attribution_expires_at ?? null;
    }

    public function index(Request $request, string $organization, string $resource)
    {
        $this->authorizeFleet($request);
        $table = $this->table($resource);
        $org = $this->organization($request);
        [$size,$cursor]=\App\Support\CursorPage::options($request);
        $page = DB::table($table)->where('organization_id', $org)
            ->orderBy('id')->cursorPaginate($size,$table === 'devices' ? \App\Services\ManagedDevices::PUBLIC_FIELDS : ['*'],'cursor',$cursor);
        $items = $page->items();
        if ($table === 'vehicles') {
            $shifts = $this->canSeeActiveShiftTarget($request, 'drivers.read')
                ? $this->activeShiftsByVehicle($org, collect($items)->pluck('id')->all())
                : collect();
            foreach ($items as $row) $this->attachVehicleShiftSummary($row, $shifts->get($row->id));
        } elseif ($table === 'drivers') {
            $shifts = $this->canSeeActiveShiftTarget($request, 'vehicles.read')
                ? $this->activeShiftsByDriver($org, collect($items)->pluck('id')->all())
                : collect();
            foreach ($items as $row) {
                unset($row->photo_path);
                $this->attachDriverShiftSummary($row, $shifts->get($row->id));
            }
        }
        return ApiResponse::success($request, ['items' => $items,'pagination'=>\App\Support\CursorPage::metadata($page)], 'List '.ucfirst($resource).' Success', 'Records retrieved.', 200);
    }

    public function store(Request $request, string $organization, string $resource)
    {
        $this->authorizeFleet($request, true);
        $table = $this->table($resource);
        $input = $this->normalize($request->validate($this->rules($table, false)));
        $org = $this->organization($request);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($org, $table, $input, $id) {
            // Serialize create/update in this registry, including normalized uniqueness checks.
            DB::table('organizations')->where('id', $org)->lockForUpdate()->first();
            if (in_array($table,['vehicles','drivers'],true)) app(\App\Services\Entitlements::class)->capacity($org,$table);
            if ($table === 'drivers') $this->driverUser($org,$input);
            $this->assertUnique($org, $table, $input);
            $now = now();
            DB::table($table)->insert(array_merge($input, [
                'id' => $id, 'organization_id' => $org, 'version' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]));
            if ($table === 'vehicles' && config('saas.managed_devices')) app(\App\Services\ManagedDevices::class)->create($org,$id);
            if ($table === 'vehicles') {
                app(\App\Services\ComplianceDefaults::class)->ensureVehicleObligations($org,$id);
                app(\App\Services\Mail\NotificationEmails::class)->organization('vehicle.registered',$id,$org,'Vehicle registered','A vehicle has been added to your fleet. Its device still requires technician installation and activation.',
                    ['Vehicle'=>$input['registration_number'],'Manufacturer'=>$input['make'] ?? null,'Model'=>$input['model'] ?? null],['vehicles.read'],['subscription_required'=>false]);
            }
        });
        $item=$this->record($request,$table,$id);
        $extra=$table === 'vehicles' ? ['device'=>DB::table('devices')->where('organization_id',$org)->where('registered_vehicle_id',$id)->first(\App\Services\ManagedDevices::PUBLIC_FIELDS)] : [];
        return ApiResponse::success($request, ['item' => $item]+$extra, 'Create '.ucfirst(rtrim($resource, 's')).' Success', 'Record created.', 201);
    }

    public function show(Request $request, string $organization, string $resource, string $id)
    {
        $this->authorizeFleet($request);
        $table = $this->table($resource);
        $org = $this->organization($request);
        $item = $this->record($request, $table, $id);
        $extra = [];
        if ($table === 'vehicles') {
            $activeShift = $this->canSeeActiveShiftTarget($request, 'drivers.read')
                ? $this->activeShiftsByVehicle($org, [$id])->get($id)
                : null;
            $this->attachVehicleShiftSummary($item, $activeShift);
            $extra = [
                'device' => DB::table('devices')->where('organization_id', $org)->where('registered_vehicle_id', $id)->first(\App\Services\ManagedDevices::PUBLIC_FIELDS),
                'active_shift' => $activeShift,
            ];
        } elseif ($table === 'drivers') {
            $activeShift = $this->canSeeActiveShiftTarget($request, 'vehicles.read')
                ? $this->activeShiftsByDriver($org, [$id])->get($id)
                : null;
            $this->attachDriverShiftSummary($item, $activeShift);
            $extra = ['active_shift' => $activeShift];
        }
        return ApiResponse::success($request, ['item' => $item]+$extra, 'Retrieve Record Success', 'Record retrieved.', 200);
    }

    public function update(Request $request, string $organization, string $resource, string $id)
    {
        $this->authorizeFleet($request, true);
        $table = $this->table($resource);
        $input = $this->normalize($request->validate(array_merge($this->rules($table, true), [
            'version' => ['required', 'integer', 'min:1'],
        ])));
        $expected = $input['version'];
        unset($input['version']);
        $org = $this->organization($request);
        $actorId = $request->user()->id;
        DB::transaction(function () use ($org, $table, $id, $input, $expected, $actorId) {
            DB::table('organizations')->where('id', $org)->lockForUpdate()->first();
            $existing = DB::table($table)->where('organization_id', $org)->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_if((int) $existing->version !== (int) $expected, 409, 'The record has changed. Refresh and try again.');
            abort_if($existing->status === 'archived', 409, 'Archived records cannot be updated.');
            if ($table === 'drivers') $this->driverUser($org,array_merge((array)$existing,$input),$id);
            $this->assertUnique($org, $table, array_merge((array) $existing, $input), $id);
            DB::table($table)->where('organization_id', $org)->where('id', $id)->update(array_merge($input, [
                'version' => $expected + 1, 'updated_at' => now(),
            ]));
            if ($table === 'drivers' && $existing->user_id) {
                $emails=app(\App\Services\Mail\NotificationEmails::class); $changed=DB::table('drivers')->where('id',$id)->first();
                if ($changed->status !== $existing->status) $emails->status('driver',$changed,$existing->user_id,$org);
                $fields=array_keys(array_filter($input,fn($value,$key)=>in_array($key,['name','email','phone'],true) && $value!==$existing->$key,ARRAY_FILTER_USE_BOTH));
                if ($fields) $emails->queue('account.details','driver:'.$id.':'.$changed->version,$existing->user_id,null,'Driver details updated','An organization updated your driver profile. Contact an administrator if you did not expect this change.',
                    ['Changed fields'=>implode(', ',$fields)]);
            }
            if ($table === 'drivers' && ($input['status'] ?? null) === 'suspended' && $existing->status !== 'suspended') {
                // Driver suspension must stop existing attribution, not merely deny
                // the next NFC tap. The organization lock serializes this with
                // authentication, revocation, and scheduled shift expiration.
                $shifts = DB::table('driver_shifts')->where('organization_id', $org)->where('driver_id', $id)->where('state', 'active')->get();
                foreach ($shifts as $shift) {
                    $end = \Carbon\CarbonImmutable::parse($shift->attribution_expires_at)->min(now())->max(\Carbon\CarbonImmutable::parse($shift->last_authenticated_at));
                    DB::table('driver_shifts')->where('id', $shift->id)->update([
                        'state' => 'closed', 'ended_at' => $end, 'closure_reason' => 'driver_suspended',
                        'version' => $shift->version + 1, 'updated_at' => now(),
                    ]);
                    DB::table('driver_shift_events')->insert([
                        'id' => (string) Str::uuid(), 'organization_id' => $org, 'shift_id' => $shift->id,
                        'actor_user_id' => $actorId, 'event' => 'driver_suspended',
                        'reason' => 'Driver suspended', 'created_at' => now(),
                    ]);
                }
            }
        });
        return ApiResponse::success($request, ['item' => $this->record($request, $table, $id)], 'Update Record Success', 'Record updated.', 200);
    }

    private function assertUnique(string $org, string $table, array $data, ?string $except = null): void
    {
        $q = DB::table($table)->where('organization_id', $org);
        if ($except) $q->where('id', '<>', $except);
        if ($table === 'vehicles') {
            $q->where('registration_country', $data['registration_country'])
                ->where('registration_normalized', $data['registration_normalized'])->where('status', '<>', 'archived');
        } elseif ($table === 'drivers') {
            if (empty($data['employee_code'])) return;
            $q->where('employee_code', $data['employee_code']);
        } else {
            $q->where('serial_number', $data['serial_number']);
        }
        abort_if($q->exists(), 409, 'A record with this identifier already exists in this organization.');
    }

    private function driverUser(string $org, array $input, ?string $except=null): void
    {
        if (empty($input['user_id'])) return;
        $member=DB::table('memberships')->where('organization_id',$org)->where('user_id',$input['user_id'])->where('role','driver')->where('status','active')->first();
        abort_unless($member,422,'Driver user must be an active driver membership in this organization.');
        abort_if(DB::table('drivers')->where('organization_id',$org)->where('user_id',$input['user_id'])->when($except,fn($q)=>$q->where('id','<>',$except))->exists(),409,'User already linked to a driver.');
    }

    public function archiveVehicle(Request $request, string $organization, string $vehicle)
    {
        $this->authorizeFleet($request, true);
        $org = $this->organization($request);
        $input = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($org, $vehicle, $input) {
            DB::table('organizations')->where('id', $org)->lockForUpdate()->first();
            $row = DB::table('vehicles')->where('organization_id', $org)->where('id', $vehicle)->lockForUpdate()->first() ?? abort(404);
            abort_if((int) $row->version !== (int) $input['version'], 409, 'The record has changed.');
            abort_if(DB::table('device_installations')->where('organization_id', $org)->where('vehicle_id', $vehicle)
                ->whereNull('effective_to')->exists(), 409, 'Close the active installation first.');
            DB::table('vehicles')->where('organization_id', $org)->where('id', $vehicle)->update([
                'status' => 'archived', 'version' => $row->version + 1, 'updated_at' => now(),
            ]);
        });
        return ApiResponse::success($request, ['item' => $this->record($request, 'vehicles', $vehicle)], 'Archive Vehicle Success', 'Vehicle archived.', 200);
    }

    public function installations(Request $request, string $organization, string $device)
    {
        $this->authorizeFleet($request);
        $this->record($request, 'devices', $device);
        [$size,$cursor]=\App\Support\CursorPage::options($request,100,['effective_from','id']);
        $page = DB::table('device_installations')->where('organization_id', $this->organization($request))
            ->where('device_id', $device)->orderByDesc('effective_from')->orderByDesc('id')->cursorPaginate($size,['*'],'cursor',$cursor);
        return ApiResponse::success($request, ['items' => $page->items(),'pagination'=>\App\Support\CursorPage::metadata($page)], 'List Installations Success', 'Installations retrieved.', 200);
    }

    public function install(Request $request, string $organization, string $device)
    {
        $this->authorizeFleet($request, true);
        $input = $request->validate(['vehicle_id' => ['required', 'uuid'], 'effective_from' => ['required', 'date']]);
        $org = $this->organization($request);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($org, $device, $input, $id) {
            // One tenant row lock serializes interval changes even when neither target has an installation.
            DB::table('organizations')->where('id', $org)->lockForUpdate()->first();
            $d = DB::table('devices')->where('organization_id', $org)->where('id', $device)->first() ?? abort(404);
            $v = DB::table('vehicles')->where('organization_id', $org)->where('id', $input['vehicle_id'])->first() ?? abort(404);
            abort_if($d->status === 'archived' || $v->status === 'archived', 409, 'Archived resources cannot be installed.');
            $from = \Illuminate\Support\Carbon::parse($input['effective_from'])->utc();
            $overlap = DB::table('device_installations')->where('organization_id', $org)
                ->where(fn ($q) => $q->where('device_id', $device)->orWhere('vehicle_id', $input['vehicle_id']))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $from))->exists();
            abort_if($overlap, 409, 'An overlapping installation already exists.');
            DB::table('device_installations')->insert([
                'id' => $id, 'organization_id' => $org, 'device_id' => $device, 'vehicle_id' => $input['vehicle_id'],
                'effective_from' => $from, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $item = DB::table('device_installations')->where('organization_id', $org)->where('id', $id)->first();
        return ApiResponse::success($request, ['item' => $item], 'Create Installation Success', 'Installation recorded; device provisioning is still required.', 201);
    }

    public function closeInstallation(Request $request, string $organization, string $device, string $installation)
    {
        $this->authorizeFleet($request, true);
        $input = $request->validate(['effective_to' => ['required', 'date'], 'reason' => ['required', 'string', 'max:255']]);
        $org = $this->organization($request);
        DB::transaction(function () use ($org, $device, $installation, $input) {
            DB::table('organizations')->where('id', $org)->lockForUpdate()->first();
            $row = DB::table('device_installations')->where('organization_id', $org)->where('device_id', $device)
                ->where('id', $installation)->lockForUpdate()->first() ?? abort(404);
            abort_if($row->effective_to !== null, 409, 'Installation is already closed.');
            $to = \Illuminate\Support\Carbon::parse($input['effective_to'])->utc();
            abort_if($to->lessThanOrEqualTo(\Illuminate\Support\Carbon::parse($row->effective_from)), 422, 'Closure must be after installation start.');
            // Shift transitions acquire the same organization lock. Never leave an
            // active shift attributed to a device after its installation ends, or
            // silently rewrite already-accepted historical attribution.
            $conflictingShift = DB::table('driver_shifts')
                ->where('organization_id', $org)->where('device_installation_id', $installation)
                ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $to))
                ->exists();
            abort_if($conflictingShift, 409, 'Close or correct affected driver shifts before ending the installation.');
            DB::table('device_installations')->where('id', $installation)->update([
                'effective_to' => $to, 'close_reason' => $input['reason'], 'updated_at' => now(),
            ]);
        });
        return ApiResponse::success($request, ['installation_id' => $installation], 'Close Installation Success', 'Installation closed.', 200);
    }
}
