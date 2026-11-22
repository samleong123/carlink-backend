<?php
namespace App\Services;

use App\Models\{Membership,Organization,User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Support\Str;

/** Shared creation path for an explicitly confirmed signup intent and an already verified account. */
class SignupCreation
{
    public function validate(array $input): array
    {
        $v=Validator::make($input,['name'=>'required|string|max:160','timezone'=>'required|timezone','default_locale'=>'required|in:en,ms,zh',
            'plan_id'=>'required|uuid','plan_version'=>'required|integer|min:1','accept_unpaid_deletion'=>'required|accepted'])->validate();
        $v['name']=trim($v['name']); abort_if($v['name'] === '',422,'Organization name is required.');
        $v['plan_version']=(int)$v['plan_version']; $v['accept_unpaid_deletion']=true;
        return $v;
    }
    public function key(?string $key): string
    {
        abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$key),422,'An Idempotency-Key of 16–128 letters, digits, underscores or hyphens is required.');
        return $key;
    }
    public function hash(array $v): string
    {
        return hash('sha256',json_encode([$v['name'],$v['timezone'],$v['default_locale'],$v['plan_id'],$v['plan_version'],true],JSON_THROW_ON_ERROR));
    }
    public function plan(array $v): object
    {
        $plan=DB::table('subscription_plans')->where('id',$v['plan_id'])->where('status','active')->where('currency','MYR')->where('price_minor','>',0)->first() ?? abort(404);
        abort_unless((int)$plan->version === (int)$v['plan_version'],409,'The plan changed. Refresh pricing and review it before signing up.');
        return $plan;
    }
    public function create(Request $r, User $user, array $v, string $key, bool $mailboxJustConfirmed=false): array
    {
        $key=$this->key($key); $hash=$this->hash($v);
        return DB::transaction(function () use ($r,$user,$v,$key,$hash,$mailboxJustConfirmed) {
            DB::table('users')->where('id',$user->id)->where('status','active')->lockForUpdate()->first() ?? abort(403);
            $existing=DB::table('organization_signups')->where('user_id',$user->id)->where('request_key_hash',hash('sha256',$key))->first();
            if ($existing) { abort_unless(hash_equals($existing->request_hash,$hash),409,'This idempotency key was used with different signup details.'); return [$existing,false]; }
            if (!$mailboxJustConfirmed) {
                $verified=$r->hasSession() ? $r->session()->get('email_link_verified_at') : null;
                abort_unless(is_int($verified) && $verified <= now()->timestamp && $verified > now()->subMinutes(10)->timestamp,403,'Confirm a fresh email sign-in link before creating an organization.');
            }
            abort_if(DB::table('organization_signups')->where('user_id',$user->id)->where('status','pending')->exists(),409,'An unpaid signup already exists. Check signup status instead of creating another organization.');
            abort_if(DB::table('organization_signups')->where('user_id',$user->id)->where('created_at','>',now()->subDay())->count()>=3,429,'Daily signup limit reached.');
            $plan=DB::table('subscription_plans')->where('id',$v['plan_id'])->where('status','active')->where('currency','MYR')->where('price_minor','>',0)->lockForUpdate()->first() ?? abort(404);
            abort_unless((int)$plan->version === (int)$v['plan_version'],409,'The plan changed. Refresh pricing and review it before signing up.');
            $org=Organization::create(['name'=>$v['name'],'slug'=>Str::slug($v['name']).'-'.Str::uuid(),'status'=>'pending_payment',
                'timezone'=>$v['timezone'],'default_locale'=>$v['default_locale'],'contact_email'=>$user->email_normalized]);
            $createdAt=\Carbon\CarbonImmutable::parse($org->created_at);
            $member=Membership::create(['organization_id'=>$org->id,'user_id'=>$user->id,'role'=>'owner','status'=>'active','joined_at'=>now()]);
            DB::table('membership_roles')->insert(['organization_id'=>$org->id,'membership_id'=>$member->id,'role_code'=>'owner']);
            app(\App\Services\ComplianceDefaults::class)->ensureTypes($org->id);
            $invoice=(string)Str::uuid(); $id=(string)Str::uuid();
            DB::table('billing_invoices')->insert(['id'=>$invoice,'number'=>'CL-'.strtoupper(str_replace('-','',$invoice)),'organization_id'=>$org->id,
                'plan_id'=>$plan->id,'plan_snapshot'=>json_encode($plan,JSON_THROW_ON_ERROR),'amount_minor'=>$plan->price_minor,'currency'=>$plan->currency,
                'issued_by'=>$user->id,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('organization_signups')->insert(['id'=>$id,'user_id'=>$user->id,'organization_id'=>$org->id,'invoice_id'=>$invoice,
                'request_key_hash'=>hash('sha256',$key),'request_hash'=>$hash,'payment_due_at'=>$createdAt->addHours(12),'created_at'=>$createdAt,'updated_at'=>$createdAt]);
            $audit=app(ActionAudit::class); $audit->outbox($audit->event('signup.create','succeeded','http',array_replace($audit->context($r),['user_id'=>$user->id,'organization_id'=>$org->id,'signup_id'=>$id,'invoice_id'=>$invoice])));
            app(\App\Services\Mail\NotificationEmails::class)->organization('invoice.issued',$invoice,$org->id,'Your CarLink organization is ready for payment',
                'Your email is confirmed and your signup invoice has been issued. Complete payment within 12 hours to activate your organization.',
                ['Invoice'=>'CL-'.strtoupper(str_replace('-','',$invoice)),'Plan'=>$plan->name,'Amount'=>sprintf('%s %d.%02d',$plan->currency,intdiv($plan->price_minor,100),$plan->price_minor%100)],
                ['subscription.read'],['subscription_required'=>false,'resource_type'=>'invoice','resource_id'=>$invoice,'resource_version'=>1]);
            return [DB::table('organization_signups')->find($id),true];
        },3);
    }
}
