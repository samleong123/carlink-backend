<?php
namespace App\Http\Controllers\Api\V1;
use App\Services\ManagedDevices;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceManagementController
{
    public function index(Request $r)
    {
        $v=$r->validate(['organization_id'=>'nullable|uuid','per_page'=>'nullable|integer|min:1|max:200','cursor'=>'nullable|string|max:512']);
        $p=DB::table('devices as d')->join('organizations as o','o.id','=','d.organization_id')->leftJoin('vehicles as v',fn($j)=>$j->on('v.id','=','d.registered_vehicle_id')->on('v.organization_id','=','d.organization_id'))
            ->when($v['organization_id'] ?? null,fn($q,$org)=>$q->where('d.organization_id',$org))->orderBy('d.id')->cursorPaginate($v['per_page'] ?? 50,
                ['d.id','d.organization_id','o.name as organization_name','d.status','d.version','v.id as vehicle_id','v.registration_number','v.make','v.model']);
        return ApiResponse::success($r,['items'=>$p->items(),'pagination'=>['next_cursor'=>$p->nextCursor()?->encode()]],'Platform Devices Retrieved');
    }
    public function show(Request $r, string $device)
    {
        $d=DB::table('devices as d')->join('organizations as o','o.id','=','d.organization_id')->where('d.id',$device)->first(['d.id','d.organization_id','o.name as organization_name','d.status','d.version','d.obd_bluetooth_mac','d.obd_uuid','d.serial_number','d.registered_vehicle_id']);
        abort_unless($d,404);
        $s=DB::table('telemetry_samples as s')->join('telemetry_inbox as i','i.id','=','s.inbox_id')->where('i.device_id',$device)->where('s.organization_id',$d->organization_id)->orderByDesc('s.received_at')->first(['s.received_at','s.network_data','s.health_data']);
        $n=json_decode($s?->network_data ?? '{}',true); $h=json_decode($s?->health_data ?? '{}',true);
        $recent=$s && \Carbon\CarbonImmutable::parse($s->received_at)->gte(now()->subSeconds(180));
        $vehicle=$d->registered_vehicle_id ? DB::table('vehicles')->where('organization_id',$d->organization_id)->where('id',$d->registered_vehicle_id)->first(['id','registration_number','make','model']) : null;
        $out=[
            'id'=>$d->id,
            'organization_id'=>$d->organization_id,
            'organization_name'=>$d->organization_name,
            'status'=>$d->status,
            'version'=>$d->version,
            'registered_vehicle_id'=>$d->registered_vehicle_id,
            'vehicle'=>$vehicle,
            'registration_number'=>$vehicle?->registration_number,
            'make'=>$vehicle?->make,
            'model'=>$vehicle?->model,
            'last_received_at'=>$s?->received_at,
            'online'=>$s ? (bool)$recent : null,
            'online_basis'=>'packet_freshness_not_heartbeat',
            'operator'=>$n['operator'] ?? null,
            'network_mode'=>$n['network_type'] ?? null,
        ];
        if ($r->attributes->get('platform_staff')->role === 'technician') {
            $out+=['network'=>$n,'device_health'=>$h,'obd_bluetooth_mac'=>$d->obd_bluetooth_mac,'obd_uuid'=>$d->obd_uuid,'serial_number'=>$d->serial_number];
        }
        return ApiResponse::success($r,['device'=>$out],'Device Status Retrieved');
    }
    public function organizationVehicles(Request $r, string $organization)
    {
        $v=$r->validate(['per_page'=>'nullable|integer|min:1|max:200','cursor'=>'nullable|string|max:512']);
        abort_unless(Str::isUuid($organization) && DB::table('organizations')->where('id',$organization)->exists(),404);
        $p=DB::table('vehicles as v')
            ->leftJoin('devices as d',fn($j)=>$j->on('d.registered_vehicle_id','=','v.id')->on('d.organization_id','=','v.organization_id'))
            ->where('v.organization_id',$organization)
            ->orderBy('v.id')
            ->cursorPaginate($v['per_page'] ?? 50,['v.id','v.registration_number','v.make','v.model','v.status','d.id as linked_device_id']);
        return ApiResponse::success($r,['items'=>$p->items(),'pagination'=>['next_cursor'=>$p->nextCursor()?->encode()]],'Organization Vehicles Retrieved');
    }
    public function configure(Request $r, string $device)
    {
        abort_unless($r->attributes->get('platform_staff')?->role === 'technician',403);
        $v=$r->validate(['version'=>'required|integer|min:1','obd_bluetooth_mac'=>['required','regex:/^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/D']]);
        $d=DB::table('devices')->where('id',$device)->first() ?? abort(404);
        DB::table('organizations')->where('id',$d->organization_id)->lockForUpdate()->first();
        $d=DB::table('devices')->where('id',$device)->lockForUpdate()->first();
        abort_if((int)$d->version !== $v['version'] || $d->status === 'archived',409);
        DB::table('devices')->where('id',$device)->update(['obd_bluetooth_mac'=>strtoupper($v['obd_bluetooth_mac']),'version'=>$d->version+1,'updated_at'=>now()]);
        return ApiResponse::success($r,['version'=>$d->version+1],'OBD Bluetooth Address Saved');
    }
    public function commissioning(Request $r, string $device)
    {
        abort_unless($r->attributes->get('platform_staff')?->role === 'technician',403);
        $d=DB::table('devices')->where('id',$device)->first() ?? abort(404);
        abort_unless($d->obd_bluetooth_mac && $d->auth_token_encrypted && $d->obd_uuid,409,'Save a MAC address on an automatically registered device first.');
        $url=config('saas.broker_url');
        abort_unless(is_string($url) && preg_match('#^(?:wss|mqtts)://#',$url),503,'Configure a secure DEVICE_MQTT_URL.');
        $rabbitmq=app(\App\Services\Broker\BrokerDeviceProvisioner::class)->provisionDevice($d,false);
        $config=['device_id'=>$device,'organization_id'=>$d->organization_id,'obd_uuid'=>$d->obd_uuid,'obd_bluetooth_mac'=>$d->obd_bluetooth_mac,
            'api_url'=>rtrim(config('app.url'),'/'),
            'broker_url'=>$url,'broker_username'=>'d-'.str_replace('-','',$device),'auth_token'=>Crypt::decryptString($d->auth_token_encrypted)];
        if ($d->registered_vehicle_id) {
            $regNumber = DB::table('vehicles')->where('id', $d->registered_vehicle_id)->value('registration_number');
            if ($regNumber) {
                $config['plate'] = $regNumber;
            }
        }
        $configJson = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $command = "sudo mkdir -p /opt/carlink /etc/carlink && cd /opt/carlink && \\\n"
            . "(sudo curl -fsSL https://github.com/samleong123/carlink-telemetry-agent/releases/latest/download/carlink-telemetry-agent-linux-arm64 -o /opt/carlink/carlink-telemetry-agent || sudo curl -fsSL https://github.com/samleong123/carlink-telemetry-agent/releases/download/1.0.0/carlink-telemetry-agent-linux-arm64 -o /opt/carlink/carlink-telemetry-agent) && \\\n"
            . "sudo chmod +x /opt/carlink/carlink-telemetry-agent && \\\n"
            . "sudo tee /opt/carlink/carlink-device.json > /dev/null << 'EOF'\n"
            . $configJson . "\n"
            . "EOF\n"
            . "sudo chmod 600 /opt/carlink/carlink-device.json && \\\n"
            . "sudo ln -sf /opt/carlink/carlink-device.json /opt/carlink/carlink_device.json && \\\n"
            . "sudo ln -sf /opt/carlink/carlink-device.json /etc/carlink/device.json && \\\n"
            . "sudo tee /etc/systemd/system/carlink-telemetry.service > /dev/null << 'EOF'\n"
            . "[Unit]\n"
            . "Description=CarLink Orange Pi Zero 3 TelemetryAgent\n"
            . "After=network-online.target bluetooth.service ModemManager.service\n"
            . "Wants=network-online.target\n\n"
            . "[Service]\n"
            . "Type=simple\n"
            . "WorkingDirectory=/opt/carlink\n"
            . "ExecStart=/opt/carlink/carlink-telemetry-agent --config /opt/carlink/carlink-device.json\n"
            . "Restart=always\n"
            . "RestartSec=5\n"
            . "StandardOutput=journal\n"
            . "StandardError=journal\n"
            . "Environment=NODE_ENV=production\n\n"
            . "[Install]\n"
            . "WantedBy=multi-user.target\n"
            . "EOF\n"
            . "sudo systemctl daemon-reload && \\\n"
            . "sudo systemctl enable --now carlink-telemetry.service && \\\n"
            . "sudo systemctl status carlink-telemetry.service --no-pager";

        return ApiResponse::success($r,[
            'command'=>$command,
            'configuration'=>$config,
            'rabbitmq'=>$rabbitmq,
            'instructions'=>'Copy and paste this command into the Orange Pi terminal to download the latest TelemetryAgent executable, write carlink-device.json, configure systemd, and start the service.'
        ], 'Pi Commissioning Configuration');
    }
    public function activate(Request $r, string $device)
    {
        abort_unless($r->attributes->get('platform_staff')?->role === 'technician',403);
        $v=$r->validate(['version'=>'required|integer|min:1','installed'=>'required|accepted','verified_broker_acls'=>'required|accepted']);
        abort_unless(config('broker.verified_topic_acls'),503,'Broker commissioning gate is not enabled.');
        $d=DB::table('devices')->where('id',$device)->first() ?? abort(404);
        DB::table('organizations')->where('id',$d->organization_id)->lockForUpdate()->first();
        $d=DB::table('devices')->where('id',$device)->lockForUpdate()->first();
        abort_if((int)$d->version !== $v['version'] || !$d->obd_bluetooth_mac || !$d->auth_token_encrypted || $d->status === 'archived',409);
        app(\App\Services\Broker\BrokerDeviceProvisioner::class)->provisionDevice($d);
        $link=DB::table('device_installations')->where('organization_id',$d->organization_id)->where('device_id',$device)->whereNull('effective_to')->first();
        if (!$link) {
            abort_unless($d->registered_vehicle_id,409);
            app(ManagedDevices::class)->link($d->organization_id,$device,$d->registered_vehicle_id);
        }
        $version=DB::table('devices')->where('id',$device)->value('version')+1;
        DB::table('devices')->where('id',$device)->update(['status'=>'active','broker_principal'=>'d-'.str_replace('-','',$device),'installed_at'=>now(),'installed_by'=>$r->user()->id,'version'=>$version,'updated_at'=>now()]);
        $installedLink=DB::table('device_installations')->where('organization_id',$d->organization_id)->where('device_id',$device)->whereNull('effective_to')->first();
        app(ManagedDevices::class)->notifyLinked($d->organization_id,$device,$installedLink->vehicle_id,$installedLink->id);
        return ApiResponse::success($r,['device_id'=>$device,'status'=>'active','version'=>$version],'Device Activated','Operator attestation recorded; this does not itself provision RabbitMQ or prove installation.');
    }
    public function link(Request $r, string $device)
    {
        $v=$r->validate(['vehicle_id'=>'required|uuid']); $d=DB::table('devices')->where('id',$device)->first() ?? abort(404);
        $id=app(ManagedDevices::class)->link($d->organization_id,$device,$v['vehicle_id']);
        return ApiResponse::success($r,['installation_id'=>$id],'Device Linked','',201);
    }
    public function unlink(Request $r, string $device)
    {
        $v=$r->validate(['reason'=>'required|string|max:255']); $d=DB::table('devices')->where('id',$device)->first() ?? abort(404);
        app(ManagedDevices::class)->unlink($d->organization_id,$device,$v['reason'],$r->user()->id);
        return ApiResponse::success($r,['device_id'=>$device],'Device Unlinked');
    }
}
