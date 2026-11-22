<?php
namespace App\Console\Commands;
use App\Services\ActionAudit;
use App\Services\Entitlements;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetainDriverHistory extends Command
{
    protected $signature='privacy:retain-driver-history';
    protected $description='Enforce subscription retention on driver logs and remove expired driver attribution; keep vehicle measurements and financial audits';
    public function handle(): int
    {
        DB::table('organizations')->orderBy('id')->chunkById(100,function($orgs) {
            foreach ($orgs as $org) DB::transaction(function () use ($org) {
                DB::table('organizations')->where('id',$org->id)->lockForUpdate()->first();
                $cut=app(Entitlements::class)->cutoff($org->id);
                $ids=DB::table('driver_shifts')->where('organization_id',$org->id)->where('state','<>','active')->whereNotNull('ended_at')->where('ended_at','<',$cut)->pluck('id');
                DB::table('driver_shift_events')->where('organization_id',$org->id)->whereIn('shift_id',$ids)->delete();
                DB::table('driver_shifts')->where('organization_id',$org->id)->whereIn('id',$ids)->delete();
                DB::table('driver_auth_events')->where('organization_id',$org->id)->where('occurred_at','<',$cut)->delete();
                DB::table('telemetry_samples')->where('organization_id',$org->id)->where('occurred_at','<',$cut)->update(['authorized_shift_id'=>null]);
                DB::table('safety_events')->where('organization_id',$org->id)->where('occurred_at','<',$cut)->delete();
                DB::table('safety_appeals')->where('organization_id',$org->id)->where('created_at','<',$cut)->delete();
                $receipts=DB::table('device_event_receipts')->where('organization_id',$org->id)->where('occurred_at','<',$cut)->pluck('id');
                DB::table('device_event_outbox')->whereIn('receipt_id',$receipts)->delete();
                DB::table('device_event_receipts')->where('organization_id',$org->id)->where('occurred_at','<',$cut)->update(['shift_id'=>null]);
                DB::table('telemetry_inbox')->where('organization_id',$org->id)->whereNotNull('projected_at')->where('occurred_at','<',$cut)->orderBy('id')->chunkById(100,function($rows) {
                    foreach ($rows as $row) {
                        $event=json_decode($row->raw_payload,false,32,JSON_THROW_ON_ERROR); unset($event->driver_session);
                        if (isset($event->payload) && is_object($event->payload)) unset($event->payload->driver_context);
                        DB::table('telemetry_inbox')->where('id',$row->id)->update(['raw_payload'=>json_encode($event,JSON_THROW_ON_ERROR)]);
                    }
                });
                $audit=app(ActionAudit::class); $audit->outbox($audit->event('privacy.driver_retention','succeeded','scheduler',['organization_id'=>$org->id,'browser_absence_reason'=>'non_browser_execution']));
            });
        });
        $this->info('Driver history retention enforced. Vehicle measurements and financial/security audit retention are separate policies.'); return self::SUCCESS;
    }
}
