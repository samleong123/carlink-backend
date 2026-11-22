<?php
namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Crypt,DB,Schema};
use Illuminate\Support\Str;

class OrganizationSignups
{
    public function pending(string $organization): ?object
    {
        return DB::table('organization_signups')->where('organization_id',$organization)->where('status','pending')->first();
    }
    public function requireOpen(string $organization): ?object
    {
        $signup=$this->pending($organization);
        abort_if($signup && CarbonImmutable::parse($signup->payment_due_at)->lessThanOrEqualTo(now()),410,'The signup payment deadline has passed. Do not pay an old checkout.');
        return $signup;
    }
    public function event(string $signup, ?string $payment, string $event, array $details=[]): void
    {
        DB::table('signup_archive_events')->insert(['id'=>(string)Str::uuid(),'signup_id'=>$signup,'payment_id'=>$payment,
            'event'=>$event,'details'=>json_encode($details,JSON_THROW_ON_ERROR),'created_at'=>now()]);
    }
    /** The organization lock serializes checkout, settlement and permanent deletion. Never delete legacy tenants. */
    public function deleteUnpaid(string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $probe=DB::table('organization_signups')->find($id);
            if (!$probe) return false;
            $org=DB::table('organizations')->where('id',$probe->organization_id)->lockForUpdate()->first();
            $signup=DB::table('organization_signups')->where('id',$id)->lockForUpdate()->first();
            if (!$org || $signup->status !== 'pending' || CarbonImmutable::parse($signup->payment_due_at)->isFuture()) return false;
            // Payment evidence protects a tenant even if a legacy/operator path missed lifecycle bookkeeping.
            if (DB::table('payment_attempts')->where('organization_id',$org->id)->where('status','succeeded')->exists()
                || DB::table('billing_invoices')->where('organization_id',$org->id)->where('status','paid')->exists()) return false;
            // Pending signups are billing-only. Unexpected operational data is a safety stop, not broad cascading deletion.
            $provisional=['memberships','membership_roles','organization_settings','organization_invitations','mail_outbox',
                'email_notifications','email_score_baselines','billing_invoices','payment_attempts','payment_attempt_events','organization_subscriptions'];
            $retained=['organization_signups','signup_payment_archives','audit_outbox'];
            foreach (Schema::getTables(Schema::getCurrentSchemaListing()) as $table) {
                $name=$table['name'];
                if (in_array($name,array_merge($provisional,$retained),true)) continue;
                $qualified=$table['schema_qualified_name'];
                if (Schema::hasColumn($qualified,'organization_id') && DB::table($qualified)->where('organization_id',$org->id)->exists()) {
                    throw new \DomainException('Unexpected operational data blocks unpaid signup deletion: '.$name);
                }
            }
            $invoices=DB::table('billing_invoices')->where('organization_id',$org->id)->get();
            $payments=DB::table('payment_attempts')->where('organization_id',$org->id)->lockForUpdate()->get();
            $events=DB::table('payment_attempt_events')->where('organization_id',$org->id)->get();
            $safePayments=$payments->map(function ($row) { $v=(array)$row; unset($v['checkout_url']); return $v; });
            $snapshot=['organization'=>$org,'invoices'=>$invoices,'payments'=>$safePayments,'payment_events'=>$events,
                'subscription'=>DB::table('organization_subscriptions')->where('organization_id',$org->id)->first(),
                'mail_evidence'=>DB::table('mail_outbox')->where('organization_id',$org->id)->get(['id','kind','source_id','state','attempts','provider_accepted_at','last_error','created_at'])];
            DB::table('signup_financial_archives')->insert(['signup_id'=>$id,'encrypted_snapshot'=>Crypt::encryptString(json_encode($snapshot,JSON_THROW_ON_ERROR)),'archived_at'=>now()]);
            foreach ($payments as $p) {
                $invoice=$invoices->firstWhere('id',$p->invoice_id);
                DB::table('signup_payment_archives')->insert(['id'=>$p->id,'signup_id'=>$id,'organization_id'=>$org->id,
                    'callback_token_hash'=>$p->callback_token_hash,'amount'=>$p->amount,'currency'=>$invoice?->currency ?? ($p->gateway_currency ?? 'MYR'),
                    'gateway_id'=>$p->gateway_id,'gateway_currency'=>$p->gateway_currency,'provider_http_status'=>$p->provider_http_status,
                    'status'=>$p->status,'reconciliation_required'=>$events->contains(fn($e)=>$e->payment_attempt_id === $p->id && $e->event === 'signup_late_callback'),
                    'late_callback_at'=>$p->failure_reason === 'signup_deadline' ? $p->callback_received_at : null,'archived_at'=>now()]);
            }
            // Child-first deletion, with FK enforcement left enabled. Accounts and other organizations remain untouched.
            foreach (['payment_attempt_events','payment_attempts','billing_invoices','organization_subscriptions','membership_roles',
                'email_notifications','email_score_baselines','mail_outbox','organization_invitations','organization_settings','memberships'] as $table) {
                DB::table($table)->where('organization_id',$org->id)->delete();
            }
            DB::table('organizations')->where('id',$org->id)->delete();
            DB::table('organization_signups')->where('id',$id)->update(['status'=>'deleted','deleted_at'=>now(),'updated_at'=>now()]);
            $this->event($id,null,'unpaid_organization_deleted',['organization_id'=>$org->id,'payment_due_at'=>$signup->payment_due_at]);
            $audit=app(ActionAudit::class); $audit->outbox($audit->event('signup.delete_unpaid','succeeded','scheduler',['organization_id'=>$org->id,'user_id'=>$signup->user_id,'signup_id'=>$id]));
            return true;
        },3);
    }
}
