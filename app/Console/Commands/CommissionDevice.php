<?php

namespace App\Console\Commands;

use App\Services\ActionAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommissionDevice extends Command
{
    protected $signature='carlink:commission-device {organization} {device} {--principal=} {--acknowledge-verified-acls}';
    protected $description='Activate a registered device after external broker commissioning and ACL acceptance tests';
    public function handle(): int
    {
        if (config('saas.managed_devices')) {
            $this->error('Managed devices must be installed and activated through the SaaS technician workflow. This command is legacy-only.'); return self::FAILURE;
        }
        $org=$this->argument('organization'); $device=$this->argument('device'); $principal=$this->option('principal');
        if (!Str::isUuid($org) || !Str::isUuid($device) || !is_string($principal) || !preg_match('/^[a-zA-Z0-9_-]{1,160}$/D',$principal) || !$this->option('acknowledge-verified-acls')) {
            $this->error('Valid UUIDs, a broker principal, and explicit ACL-verification attestation are required.'); return self::FAILURE;
        }
        try {
            DB::transaction(function () use ($org,$device,$principal) {
                $o=DB::table('organizations')->where('id',$org)->where('status','active')->lockForUpdate()->first();
                $d=DB::table('devices')->where('organization_id',$org)->where('id',$device)->lockForUpdate()->first();
                if (!$o || !$d || $d->status === 'archived') throw new \DomainException('Invalid commissioning target.');
                if (DB::table('devices')->where('broker_principal',$principal)->where('id','<>',$device)->exists()) throw new \DomainException('Principal is already assigned.');
                DB::table('devices')->where('id',$device)->update(['broker_principal'=>$principal,'status'=>'active','version'=>$d->version+1,'updated_at'=>now()]);
                $audit=app(ActionAudit::class); $audit->outbox($audit->event('device.commission','succeeded','cli',['organization_id'=>$org,'device_id'=>$device,'browser_absence_reason'=>'non_browser_execution']));
            });
        } catch (\Throwable $e) { $this->error('Commissioning failed; check target and database configuration.'); return self::FAILURE; }
        $this->info('Device activated. This command records operator attestation; it does not test broker security.'); return self::SUCCESS;
    }
}
