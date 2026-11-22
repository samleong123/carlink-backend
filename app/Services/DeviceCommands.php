<?php
namespace App\Services;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceCommands
{
    public function stationaryMaxAgeSeconds(string $org): int
    {
        return app(Entitlements::class)->deviceOfflineAfterSeconds($org);
    }

    public function stationary(string $org, string $vehicle): void
    {
        $maxAge = $this->stationaryMaxAgeSeconds($org);
        $s=DB::table('vehicle_latest_states as l')->join('telemetry_samples as s','s.id','=','l.sample_id')->where('l.organization_id',$org)->where('l.vehicle_id',$vehicle)->first(['s.speed_kmh','s.occurred_at','s.received_at','s.installation_id']);
        abort_unless($s && $s->speed_kmh !== null && (float)$s->speed_kmh === 0.0
            && \Carbon\CarbonImmutable::parse($s->received_at)->greaterThanOrEqualTo(now()->subSeconds($maxAge))
            && DB::table('device_installations')->where('id',$s->installation_id)->whereNull('effective_to')->exists(),409,'A fresh stationary OBD reading is required.');
    }
    public function issue(string $org, string $vehicle, string $kind, array $parameters, string $actor): string
    {
        DB::table('organizations')->where('id',$org)->lockForUpdate()->first();
        $link=DB::table('device_installations')->where('organization_id',$org)->where('vehicle_id',$vehicle)->whereNull('effective_to')->first() ?? abort(409,'No installed device.');
        $d=DB::table('devices')->where('organization_id',$org)->where('id',$link->device_id)->where('status','active')->lockForUpdate()->first() ?? abort(409);
        abort_unless($d->auth_token_encrypted,409,'Commission a managed device before sending commands.');
        abort_if(DB::table('device_commands')->where('device_id',$d->id)->whereIn('state',['pending','running'])->where('expires_at','>',now())->exists(),409,'Another device operation is pending.');
        $id=(string)Str::uuid(); $expires=now()->addSeconds($kind === 'extended_dtc' ? 30 : 300);
        $wire=json_encode(['id'=>$id,'organization_id'=>$org,'device_id'=>$d->id,'vehicle_id'=>$vehicle,'installation_id'=>$link->id,'kind'=>$kind,'issued_at'=>now()->toIso8601String(),'expires_at'=>$expires->toIso8601String(),'parameters'=>(object)$parameters],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        DB::table('device_commands')->insert(['id'=>$id,'organization_id'=>$org,'device_id'=>$d->id,'vehicle_id'=>$vehicle,'kind'=>$kind,'payload'=>$wire,
            'signature'=>hash_hmac('sha256',$wire,Crypt::decryptString($d->auth_token_encrypted)),'requested_by'=>$actor,'expires_at'=>$expires,'created_at'=>now(),'updated_at'=>now()]);
        return $id;
    }
}
