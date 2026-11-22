<?php
namespace App\Services\Mail;
use App\Http\Controllers\Api\V1\SafetyController;
use App\Models\Membership;
use App\Services\{Entitlements,Permissions};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ScheduledEmails
{
    public function run(bool $force = false): void
    {
        DB::table('email_score_baselines')->where('checked_on','<',now()->subDays(3)->toDateString())->delete();
        // Chunk every retained organization; deduplication makes overlapping/manual runs resumable.
        DB::table('organizations')->where('status','active')->orderBy('id')->chunkById(50,function($orgs) use ($force) {
            foreach ($orgs as $org) DB::transaction(fn()=> $this->organization($org, $force));
        });
    }
    private function organization(object $org, bool $force = false): void
    {
        DB::table('organizations')->where('id',$org->id)->lockForUpdate()->first();
        $settings=json_decode(DB::table('organization_settings')->where('organization_id',$org->id)->value('settings') ?? '{}',true);
        $now=CarbonImmutable::now($org->timezone); if (!$force && $now->hour < ($settings['email_digest_hour'] ?? 8)) return;
        $emails=app(NotificationEmails::class); $entitlements=app(Entitlements::class);
        $sub=$entitlements->subscription($org->id);
        if ($sub?->expires_at && $sub->status==='active') {
            $expires=CarbonImmutable::parse($sub->expires_at)->setTimezone($org->timezone);
            $remaining=(int)CarbonImmutable::parse($now->toDateString(),'UTC')->diffInDays(CarbonImmutable::parse($expires->toDateString(),'UTC'),false);
            if ($remaining<=($settings['email_subscription_warning_days'] ?? 30)) $emails->organization('subscription.expiry',$org->id.':'.$sub->expires_at.':'.($remaining<0 ? 'expired' : 'warning'),$org->id,
                $remaining<0 ? 'Your subscription period has ended' : 'Your subscription is due for renewal','Review your plan and renew in CarLink to maintain fleet access.',
                ['Period ends'=>$expires->format('d M Y, H:i P').' ('.$org->timezone.')','Calendar days remaining'=>$remaining],['subscription.read'],['subscription_required'=>false,'resource_type'=>'subscription','resource_id'=>$org->id,'resource_version'=>$sub->version]);
        }
        if (!$entitlements->active($org->id)) return;
        $to=$now->startOfDay()->utc(); $from=$now->startOfDay()->subDay()->utc(); $day=$now->subDay()->toDateString();
        $safety=!config('saas.enforce_subscriptions') || in_array($entitlements->plan($org->id)['safety_enabled'] ?? false,[true,1],true);
        $note='Partial-coverage estimates from reported telemetry only. Missing measurements are not zero. Harsh-driving observations and scores are experimental, uncalibrated and not distance-normalized; do not use them alone for disciplinary decisions. Late data may change the live report.';
        $period=['Local report date'=>$day,'Report timezone'=>$org->timezone,'Window starts'=>$from->setTimezone($org->timezone)->format('d M Y, H:i P'),'Window ends (exclusive)'=>$to->setTimezone($org->timezone)->format('d M Y, H:i P')];
        $analytics=app(DrivingEmailAnalytics::class);
        $fleetPermissions=array_merge(['dashboard.read','telemetry.read'],$safety ? ['safety.read'] : []);
        if (($settings['email_organization_daily'] ?? true) && $emails->organizationNeeds('analytics.organization_daily',$day,$org->id,$fleetPermissions) && $from->gte($entitlements->cutoff($org->id))) $emails->organization('analytics.organization_daily',$day,$org->id,'Your fleet’s daily driving summary','A snapshot of the whole organization for the completed local day.',
            $period+$analytics->summarize($org->id,$from,$to,null,$safety),array_merge(['dashboard.read','telemetry.read'],$safety ? ['safety.read'] : []),['safety_required'=>$safety,'period_start'=>$from],$note);
        foreach (DB::table('drivers')->where('organization_id',$org->id)->where('status','active')->whereNotNull('user_id')->orderBy('id')->cursor() as $driver) {
            $member=Membership::where('organization_id',$org->id)->where('user_id',$driver->user_id)->where('status','active')->first();
            if (!app(Permissions::class)->allows($member,'profile.own')) continue;
            $policy=['permissions'=>['profile.own'],'driver_id'=>$driver->id,'period_start'=>$from];
            if (($settings['email_driver_daily'] ?? true) && !$emails->wasQueued('analytics.driver_daily',$driver->id.':'.$day,$driver->user_id,$org->id) && $from->gte($entitlements->cutoff($org->id))) $emails->queue('analytics.driver_daily',$driver->id.':'.$day,$driver->user_id,$org->id,'Your daily driving summary','Your own authorized driving measurements for the completed local day.',
                $period+$analytics->summarize($org->id,$from,$to,$driver->id,$safety),$policy+['safety_required'=>$safety],null,$note);
            if (!$safety) continue;
            if (($settings['email_driver_weekly'] ?? true) && $now->isoWeekday()===($settings['email_weekly_iso_day'] ?? 1) && !$emails->wasQueued('analytics.driver_weekly',$driver->id.':'.$to->toDateString(),$driver->user_id,$org->id)) {
                $weekFrom=$now->startOfDay()->subDays(7)->utc();
                $emails->queue('analytics.driver_weekly',$driver->id.':'.$to->toDateString(),$driver->user_id,$org->id,'Your weekly driving observations','Harsh braking and acceleration observations for the seven completed local days.',
                    ['Local start date'=>$weekFrom->setTimezone($org->timezone)->toDateString(),'Local end date (exclusive)'=>$now->toDateString(),'Report timezone'=>$org->timezone]+$analytics->summarize($org->id,$weekFrom,$to,$driver->id,true),
                    ['period_start'=>$weekFrom,'safety_required'=>true]+$policy,null,$note);
            }
            $this->score($org,$driver,$now,$settings,$note);
        }
    }
    private function score(object $org,object $driver,CarbonImmutable $now,array $settings,string $note): void
    {
        $old=DB::table('email_score_baselines')->where('organization_id',$org->id)->where('driver_id',$driver->id)->first();
        if ($old && $old->checked_on===$now->toDateString()) return;
        $data=app(SafetyController::class)->scoreData($org->id,$driver->id);
        $basis=hash('sha256',json_encode([$data['algorithm'],app(Entitlements::class)->plan($org->id)['retention_days'] ?? 30],JSON_THROW_ON_ERROR));
        if (($settings['email_score_changes'] ?? true) && $old && $old->user_id===$driver->user_id && $old->basis===$basis && $old->checked_on===$now->subDay()->toDateString() && $old->score!==null && $data['score']!==null && (int)$old->score!==$data['score']) {
            app(NotificationEmails::class)->queue('analytics.score_change',$driver->id.':'.$now->toDateString(),$driver->user_id,$org->id,'Your driving score '.($data['score']>(int)$old->score ? 'increased' : 'decreased'),
                'Your retained-history experimental score changed since the previous daily check.',
                ['Previous score'=>$old->score,'Current score'=>$data['score'],'Included observations'=>$data['events_included'],'Checked at'=>$now->format('d M Y, H:i P').' ('.$org->timezone.')'],
                ['permissions'=>['profile.own'],'driver_id'=>$driver->id,'safety_required'=>true,'retention_days'=>app(Entitlements::class)->plan($org->id)['retention_days'] ?? 30],null,$note.' A change can reflect appeals, retention expiry, corrections or late telemetry—not only new driving.');
        }
        DB::table('email_score_baselines')->updateOrInsert(['organization_id'=>$org->id,'driver_id'=>$driver->id],['user_id'=>$driver->user_id,'score'=>$data['score'],'basis'=>$basis,'checked_on'=>$now->toDateString(),'created_at'=>$old?->created_at ?? now(),'updated_at'=>now()]);
    }
}
