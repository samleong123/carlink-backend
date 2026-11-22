<?php
namespace App\Services\Mail;

use App\Models\Membership;
use App\Services\{Entitlements,Permissions};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationEmails
{
    public function queue(string $event,string $source,string $user,?string $org,string $title,string $intro,array $details=[],array $policy=[],?string $url=null,string $note=''): ?string
    {
        if (DB::transactionLevel()<1) throw new \LogicException('Email notifications must commit with their domain event.');
        $hash=hash('sha256',json_encode([$event,$source,$user,$org],JSON_THROW_ON_ERROR));
        $id=(string)Str::uuid();
        $row=['id'=>$id,'user_id'=>$user,'organization_id'=>$org,'event_key'=>$event,'deduplication_hash'=>$hash,
            'scope'=>$policy['scope'] ?? ($org ? 'tenant' : 'account'),'scope_id'=>$policy['scope_id'] ?? null,'scope_version'=>$policy['scope_version'] ?? null,
            'permissions'=>json_encode($policy['permissions'] ?? [],JSON_THROW_ON_ERROR),'driver_id'=>$policy['driver_id'] ?? null,'alert_id'=>$policy['alert_id'] ?? null,
            'safety_required'=>$policy['safety_required'] ?? false,'subscription_required'=>$policy['subscription_required'] ?? str_starts_with($event,'analytics.'),
            'period_start'=>$policy['period_start'] ?? null,'resource_type'=>$policy['resource_type'] ?? null,'resource_id'=>$policy['resource_id'] ?? null,
            'resource_version'=>$policy['resource_version'] ?? null,'retention_days'=>$policy['retention_days'] ?? null,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()];
        if (!$this->eligible((object)$row)) return null;
        if (!DB::table('email_notifications')->insertOrIgnore($row)) return null;
        $recipient=DB::table('users')->where('id',$user)->value('email_normalized');
        if ($row['scope']==='tenant') $details=['Organization'=>DB::table('organizations')->where('id',$org)->value('name')]+$details;
        $message=app(EmailTemplate::class)->render($title,$intro,$details,$url ?? rtrim(config('app.frontend_url'),'/').'/#/console','Open CarLink',$note);
        return app(DurableMail::class)->enqueue('notification',$id,$user,$org,$title,$message['body'],now()->addHours(48),$message['html'],$recipient);
    }
    public function eligible(object $row): bool
    {
        $user=DB::table('users')->where('id',$row->user_id)->first(); if (!$user) return false;
        if ($row->scope==='account_status') {
            $tables=['user'=>'users','membership'=>'memberships','platform'=>'saas_admins','driver'=>'drivers'];
            // Scope target type is encoded in permissions for status notices; never business information.
            $type=json_decode($row->permissions,true)[0] ?? ''; if (!isset($tables[$type])) return false;
            $q=DB::table($tables[$type])->where($type==='platform' ? 'user_id' : 'id',$row->scope_id);
            if (in_array($type,['membership','driver'],true)) $q->where('organization_id',$row->organization_id)->where('user_id',$row->user_id);
            if ($type==='user' && $row->scope_id !== $row->user_id) return false;
            if ($type==='platform' && $row->scope_id !== $row->user_id) return false;
            $target=$q->first();
            return $target && (int)$target->version===(int)$row->scope_version && in_array($user->status,['active','suspended'],true);
        }
        if ($row->scope==='account') return in_array($user->status,['active','suspended'],true);
        if ($user->status!=='active') return false;
        $org=DB::table('organizations')->where('id',$row->organization_id)->whereIn('status',['active','pending_payment'])->first(); if (!$org) return false;
        if ($org->status === 'pending_payment') {
            if ($row->event_key !== 'invoice.issued' || $row->subscription_required) return false;
            $signup=DB::table('organization_signups')->where('organization_id',$org->id)->where('status','pending')->first();
            if (!$signup || CarbonImmutable::parse($signup->payment_due_at)->lessThanOrEqualTo(now())) return false;
        }
        $settings=json_decode(DB::table('organization_settings')->where('organization_id',$org->id)->value('settings') ?? '{}',true);
        $preferences=['analytics.organization_daily'=>'email_organization_daily','analytics.driver_daily'=>'email_driver_daily','analytics.driver_weekly'=>'email_driver_weekly','analytics.score_change'=>'email_score_changes'];
        if (isset($preferences[$row->event_key]) && !($settings[$preferences[$row->event_key]] ?? true)) return false;
        $member=Membership::where('organization_id',$org->id)->where('user_id',$user->id)->where('status','active')->first(); if (!$member) return false;
        $permissions=json_decode($row->permissions,true);
        foreach ($permissions as $permission) if (!app(Permissions::class)->allows($member,$permission)) return false;
        $entitlements=app(Entitlements::class);
        if ($row->subscription_required && config('saas.enforce_subscriptions') && !$entitlements->active($org->id)) return false;
        if ($row->safety_required && config('saas.enforce_subscriptions') && !in_array($entitlements->plan($org->id)['safety_enabled'] ?? false,[true,1],true)) return false;
        if ($row->period_start && CarbonImmutable::parse($row->period_start)->lt($entitlements->cutoff($org->id))) return false;
        if (($row->retention_days ?? null)!==null && (int)$row->retention_days!==(int)($entitlements->plan($org->id)['retention_days'] ?? 30)) return false;
        if ($row->driver_id && !DB::table('drivers')->where('organization_id',$org->id)->where('id',$row->driver_id)->where('user_id',$user->id)->where('status','active')->exists()) return false;
        return app(NotificationSourcePolicy::class)->eligible($row);
    }
    public function wasQueued(string $event,string $source,string $user,string $org): bool
    {
        return DB::table('email_notifications')->where('deduplication_hash',hash('sha256',json_encode([$event,$source,$user,$org],JSON_THROW_ON_ERROR)))->exists();
    }
    public function organizationNeeds(string $event,string $source,string $org,array $permissions): bool
    {
        foreach (Membership::where('organization_id',$org)->where('status','active')->cursor() as $member) {
            if (!array_diff($permissions,app(Permissions::class)->keys($member)) && !$this->wasQueued($event,$source,$member->user_id,$org)) return true;
        }
        return false;
    }
    public function organization(string $event,string $source,string $org,string $title,string $intro,array $details,array $permissions,array $policy=[],string $note=''): void
    {
        foreach (Membership::where('organization_id',$org)->where('status','active')->get() as $member) {
            $this->queue($event,$source,$member->user_id,$org,$title,$intro,$details,['permissions'=>$permissions]+$policy,null,$note);
        }
    }
    public function status(string $type,object $target,string $user,?string $org): void
    {
        $id=$type==='platform' ? $target->user_id : $target->id;
        $this->queue('account.status',$type.':'.$id.':'.$target->version,$user,$org,'Account access '.($target->status==='active' ? 'activated' : $target->status),
            'Your account access has changed. This notice applies only to the access scope shown below.',
            ['Access scope'=>$type==='user' ? 'CarLink account' : ($type==='platform' ? 'SaaS staff' : ($type==='driver' ? 'Driver profile' : 'Organization membership')),'Status'=>$target->status],
            ['scope'=>'account_status','scope_id'=>$id,'scope_version'=>$target->version,'permissions'=>[$type],'subscription_required'=>false]);
    }
    public function localTime(mixed $at,string $user): string
    {
        $timezone=DB::table('users')->where('id',$user)->value('timezone') ?: 'Asia/Kuala_Lumpur';
        return CarbonImmutable::parse($at)->setTimezone($timezone)->format('d M Y, H:i:s P').' ('.$timezone.')';
    }
}
