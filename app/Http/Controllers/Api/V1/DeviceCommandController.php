<?php
namespace App\Http\Controllers\Api\V1;
use App\Services\DeviceCommands;
use App\Services\Entitlements;
use App\Services\Permissions;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceCommandController
{
    private function effectiveOrganizationSettings(string $organizationId): array
    {
        $row = DB::table('organization_settings')
            ->where('organization_id', $organizationId)
            ->first(['settings', 'version']);
        $raw = $row?->settings ? (json_decode($row->settings, true) ?: []) : [];
        $plan = app(Entitlements::class)->plan($organizationId);
        $enforced = (bool) config('saas.enforce_subscriptions');

        $interval = max(
            2,
            (int) ($raw['telemetry_interval_seconds'] ?? 5),
            (int) ($plan['telemetry_min_seconds'] ?? 2)
        );

        return [
            'version' => (int) ($row->version ?? 1),
            'settings' => [
                'telemetry_interval_seconds' => min(30, $interval),
                'cellular_enabled' => !$enforced || in_array($plan['cellular_enabled'] ?? false, [true, 1], true),
                'safety_enabled' => !$enforced || in_array($plan['safety_enabled'] ?? false, [true, 1], true),
                'harsh_braking_ms2' => (float) ($raw['harsh_braking_ms2'] ?? 3.0),
                'rapid_acceleration_ms2' => (float) ($raw['rapid_acceleration_ms2'] ?? 3.0),
                'sharp_cornering_ms2' => (float) ($raw['sharp_cornering_ms2'] ?? 3.5),
            ],
        ];
    }

    public function scan(Request $r, string $organization, string $vehicle)
    {
        abort_unless(app(Permissions::class)->allows($r->attributes->get('membership'),'diagnostics.run'),403);
        $r->validate(['acknowledge_interruption'=>'required|accepted']);
        return DB::transaction(function () use ($r,$organization,$vehicle) {
            app(DeviceCommands::class)->stationary($organization,$vehicle);
            $id=app(DeviceCommands::class)->issue($organization,$vehicle,'extended_dtc',['read_only'=>true,'max_duration_seconds'=>300,'local_stationary_check_required'=>true],$r->user()->id);
            return ApiResponse::success($r,['command_id'=>$id,'state'=>'pending','obd_interruption_seconds'=>[120,300]],'Extended DTC Requested','Queued, not executed. The Pi must independently confirm stationary speed, pause polling and restore it afterward.',202);
        });
    }
    public function status(Request $r, string $organization, string $command)
    {
        abort_unless(app(Permissions::class)->allows($r->attributes->get('membership'),'devices.read'),403);
        $row=DB::table('device_commands')->where('organization_id',$organization)->where('id',$command)->first(['id','vehicle_id','kind','state','expires_at','started_at','result_status','created_at']) ?? abort(404);
        return ApiResponse::success($r,['command'=>$row],'Device Command Retrieved');
    }
    public function settings(Request $r, string $device)
    {
        $d=$r->attributes->get('authenticated_device');
        return ApiResponse::success($r,$this->effectiveOrganizationSettings($d->organization_id),'Device Organization Settings Retrieved');
    }
    public function pending(Request $r, string $device)
    {
        $d=$r->attributes->get('authenticated_device');
        $rows=DB::table('device_commands')->where('organization_id',$d->organization_id)->where('device_id',$device)->where('state','pending')->where('expires_at','>',now())->orderBy('created_at')->limit(10)->get(['id','payload','signature']);
        foreach ($rows as $row) { $row->wire_payload=$row->payload; unset($row->payload); }
        $settingsVersion=(int)(DB::table('organization_settings')->where('organization_id',$d->organization_id)->value('version') ?? 1);
        return ApiResponse::success($r,['items'=>$rows,'settings_version'=>$settingsVersion],'Pending Device Commands Retrieved');
    }
    public function result(Request $r, string $device, string $command)
    {
        $v=$r->validate(['state'=>'required|in:running,completed,failed','result_status'=>'required|in:started,completed,not_stationary,unsupported,transport_failed,timed_out',
            'speed_kmh'=>'sometimes|numeric','speed_observed_at'=>'sometimes|date','codes'=>'prohibited','dtc_codes'=>'prohibited','raw'=>'prohibited','result'=>'prohibited']);
        // Reject unknown fields rather than ever writing an opaque diagnostic result.
        abort_if(array_diff(array_keys($r->all()),array_keys($v)),422);
        return DB::transaction(function () use ($r,$device,$command,$v) {
            $d=$r->attributes->get('authenticated_device');
            DB::table('organizations')->where('id',$d->organization_id)->lockForUpdate()->first();
            $row=DB::table('device_commands')->where('organization_id',$d->organization_id)->where('device_id',$device)->where('id',$command)->lockForUpdate()->first() ?? abort(404);
            abort_unless(in_array($row->state,['pending','running'],true) && \Carbon\CarbonImmutable::parse($row->expires_at)->isFuture(),409);
            abort_unless(DB::table('device_installations')->where('organization_id',$d->organization_id)->where('device_id',$device)->where('vehicle_id',$row->vehicle_id)->whereNull('effective_to')->exists(),409);
            if ($v['state'] === 'running') {
                abort_unless($row->state === 'pending' && $v['result_status'] === 'started',409);
                if ($row->kind === 'extended_dtc') {
                    app(DeviceCommands::class)->stationary($d->organization_id,$row->vehicle_id);
                    abort_unless(isset($v['speed_kmh'],$v['speed_observed_at']) && (float)$v['speed_kmh'] === 0.0 && \Carbon\CarbonImmutable::parse($v['speed_observed_at'])->betweenIncluded(now()->subSeconds(2),now()),409);
                }
            } else {
                abort_unless(($v['state'] === 'completed' && $v['result_status'] === 'completed' && $row->state === 'running') || ($v['state'] === 'failed' && !in_array($v['result_status'],['started','completed'],true)),409);
            }
            $update=['state'=>$v['state'],'result_status'=>$v['result_status'],'updated_at'=>now()];
            if ($v['state'] === 'running') $update+=['started_at'=>now(),'expires_at'=>now()->addSeconds(300)];
            DB::table('device_commands')->where('id',$command)->update($update);
            return ApiResponse::success($r,['command_id'=>$command,'state'=>$v['state']],'Device Command Status Recorded');
        });
    }
}
