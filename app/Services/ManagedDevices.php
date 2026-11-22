<?php
namespace App\Services;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManagedDevices
{
    public const PUBLIC_FIELDS=['id','organization_id','serial_number','name','status','obd_uuid','registered_vehicle_id','app_version','version','created_at','updated_at'];
    public function create(string $org, string $vehicle): object
    {
        $id=(string)Str::uuid(); $token=bin2hex(random_bytes(32));
        DB::table('devices')->insert(['id'=>$id,'organization_id'=>$org,'serial_number'=>'pi-'.$id,'status'=>'registered','obd_uuid'=>(string)Str::uuid(),
            'registered_vehicle_id'=>$vehicle,'auth_token_encrypted'=>Crypt::encryptString($token),'version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        return DB::table('devices')->where('id',$id)->first(self::PUBLIC_FIELDS);
    }
    public function link(string $org, string $device, string $vehicle): string
    {
        // Every path uses the same tenant mutex; history is append-only, links begin now.
        DB::table('organizations')->where('id',$org)->lockForUpdate()->first() ?? abort(404);
        $d=DB::table('devices')->where('organization_id',$org)->where('id',$device)->lockForUpdate()->first() ?? abort(404);
        $v=DB::table('vehicles')->where('organization_id',$org)->where('id',$vehicle)->first() ?? abort(404);
        abort_if($d->status === 'archived' || $v->status === 'archived',409);
        abort_if($d->registered_vehicle_id && $d->registered_vehicle_id !== $vehicle,409,'Unlink the current vehicle first.');
        abort_if(DB::table('devices')->where('organization_id',$org)->where('registered_vehicle_id',$vehicle)->where('id','<>',$device)->exists(),409,'Vehicle already has a pending or installed device.');
        abort_if(DB::table('device_installations')->where('organization_id',$org)->whereNull('effective_to')->where(fn($q)=>$q->where('device_id',$device)->orWhere('vehicle_id',$vehicle))->exists(),409,'Device or vehicle already linked.');
        $id=(string)Str::uuid();
        DB::table('device_installations')->insert(['id'=>$id,'organization_id'=>$org,'device_id'=>$device,'vehicle_id'=>$vehicle,'effective_from'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('devices')->where('id',$device)->update(['registered_vehicle_id'=>$vehicle,'version'=>$d->version+1,'updated_at'=>now()]);
        if ($d->status==='active') $this->notifyLinked($org,$device,$vehicle,$id);
        return $id;
    }
    public function unlink(string $org, string $device, string $reason, string $actor): void
    {
        DB::table('organizations')->where('id',$org)->lockForUpdate()->first() ?? abort(404);
        $d=DB::table('devices')->where('organization_id',$org)->where('id',$device)->lockForUpdate()->first() ?? abort(404);
        $link=DB::table('device_installations')->where('organization_id',$org)->where('device_id',$device)->whereNull('effective_to')->lockForUpdate()->first();
        abort_unless($link || $d->registered_vehicle_id,409,'Device is not associated with a vehicle.');
        if ($link) abort_if(now()->lessThanOrEqualTo(\Carbon\CarbonImmutable::parse($link->effective_from)),409,'Link must exist before it can be closed.');
        $shifts=$link ? DB::table('driver_shifts')->where('organization_id',$org)->where('device_installation_id',$link->id)->whereNull('ended_at')->get() : [];
        foreach ($shifts as $s) {
            DB::table('driver_shifts')->where('id',$s->id)->update(['ended_at'=>now(),'state'=>'closed','closure_reason'=>'device_unlinked','version'=>$s->version+1,'updated_at'=>now()]);
            DB::table('driver_shift_events')->insert(['id'=>(string)Str::uuid(),'organization_id'=>$org,'shift_id'=>$s->id,'actor_user_id'=>$actor,'event'=>'device_unlinked','reason'=>$reason,'created_at'=>now()]);
        }
        if ($link) DB::table('device_installations')->where('id',$link->id)->update(['effective_to'=>now(),'close_reason'=>$reason,'updated_at'=>now()]);
        DB::table('device_commands')->where('device_id',$device)->whereIn('state',['pending','running'])->update(['state'=>'cancelled','updated_at'=>now()]);
        DB::table('devices')->where('id',$device)->update(['registered_vehicle_id'=>null,'version'=>$d->version+1,'updated_at'=>now()]);
        app(\App\Services\Mail\NotificationEmails::class)->organization('device.unlinked',$device.':'.($d->version+1),$org,'Device unlinked','A SaaS administrator removed a device association. Existing driver attribution has been closed.',
            ['Device UUID'=>$device,'Vehicle UUID'=>$link?->vehicle_id ?? $d->registered_vehicle_id],['vehicles.read'],['subscription_required'=>false]);
    }
    public function notifyLinked(string $org,string $device,string $vehicle,string $installation): void
    {
        $v=DB::table('vehicles')->where('organization_id',$org)->where('id',$vehicle)->first() ?? throw new \DomainException('Missing linked vehicle.');
        app(\App\Services\Mail\NotificationEmails::class)->organization('device.enabled_linked',$installation,$org,'Your vehicle device is enabled','The technician recorded installation and enabled the device linked to your vehicle.',
            ['Vehicle'=>$v->registration_number,'Manufacturer'=>$v->make,'Model'=>$v->model,'Device UUID'=>$device],['vehicles.read'],['resource_type'=>'installation','resource_id'=>$installation,'subscription_required'=>false], 'Activation is an operator attestation, not a guarantee of online telemetry or hardware health.');
    }
}
