<?php
namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class Entitlements
{
    public function subscription(string $org): ?object { return DB::table('organization_subscriptions')->where('organization_id',$org)->first(); }
    public function plan(string $org): array
    {
        $row=$this->subscription($org);
        return $row?->plan_snapshot ? json_decode($row->plan_snapshot,true,32,JSON_THROW_ON_ERROR) : [];
    }
    public function active(string $org): bool
    {
        if (DB::table('organizations')->where('id',$org)->where('status','pending_payment')->exists()) return false;
        if (!config('saas.enforce_subscriptions')) return true;
        $row=$this->subscription($org);
        return $row && $row->status === 'active' && $row->expires_at && CarbonImmutable::parse($row->expires_at)->isFuture();
    }
    public function requireActive(string $org): void { abort_unless($this->active($org),402,'An active paid subscription is required.'); }
    public function feature(string $org, string $feature): void
    {
        $this->requireActive($org);
        if (config('saas.enforce_subscriptions')) abort_unless(in_array($this->plan($org)[$feature.'_enabled'] ?? false,[true,1],true),403,'Feature not included in this subscription.');
    }
    /** Call under the organization's row lock, including suspended/inactive registered resources. */
    public function capacity(string $org, string $resource): void
    {
        $this->requireActive($org);
        if (!config('saas.enforce_subscriptions')) return;
        $key=$resource === 'vehicles' ? 'vehicle_limit' : 'driver_limit';
        $count=DB::table($resource)->where('organization_id',$org)->when($resource === 'vehicles',fn($q)=>$q->where('status','<>','archived'))->count();
        abort_if($count >= ($this->plan($org)[$key] ?? 0),409,'Registered resource limit reached.');
    }
    public function cutoff(string $org): CarbonImmutable { return CarbonImmutable::now('UTC')->subDays($this->plan($org)['retention_days'] ?? 30); }
    public function deviceOfflineAfterSeconds(string $org): int
    {
        $raw = DB::table('organization_settings')->where('organization_id', $org)->value('settings');
        $settings = $raw ? (json_decode($raw, true) ?: []) : [];
        return max(90, min(3600, (int) ($settings['device_offline_after_s'] ?? 180)));
    }
    public function effectiveTelemetryIntervalSeconds(string $org, int $defaultSeconds = 2): int
    {
        $raw = DB::table('organization_settings')->where('organization_id', $org)->value('settings');
        $settings = $raw ? (json_decode($raw, true) ?: []) : [];
        return max(
            2,
            min(30, (int) ($settings['telemetry_interval_seconds'] ?? $defaultSeconds)),
            (int) ($this->plan($org)['telemetry_min_seconds'] ?? 2)
        );
    }
    public function maxCompatibleGapSeconds(string $org): int
    {
        $interval = $this->effectiveTelemetryIntervalSeconds($org, 2);
        return max(15, min(45, $interval + 5));
    }
    public function settle(object $payment): void
    {
        if (!$payment->invoice_id) return;
        DB::table('organizations')->where('id',$payment->organization_id)->lockForUpdate()->first() ?? throw new \DomainException('Missing invoice organization.');
        $signup=app(OrganizationSignups::class)->requireOpen($payment->organization_id);
        $invoice=DB::table('billing_invoices')->where('id',$payment->invoice_id)->lockForUpdate()->first();
        if (!$invoice || $invoice->status === 'paid') return;
        if ($invoice->payment_id !== $payment->id || $invoice->organization_id !== $payment->organization_id || (int)round((float)$payment->amount*100) !== (int)$invoice->amount_minor) throw new \DomainException('Invoice payment mismatch.');
        if (($payment->callback_currency && $payment->callback_currency !== $invoice->currency) || ($payment->gateway_currency && $payment->gateway_currency !== $invoice->currency)) throw new \DomainException('Invoice currency mismatch.');
        $org=DB::table('organizations')->where('id',$invoice->organization_id)->lockForUpdate()->first();
        $sub=$this->subscription($org->id); $plan=json_decode($invoice->plan_snapshot,true,32,JSON_THROW_ON_ERROR);
        $base=$sub?->expires_at && CarbonImmutable::parse($sub->expires_at)->isFuture() ? CarbonImmutable::parse($sub->expires_at) : CarbonImmutable::now('UTC');
        $values=['plan_id'=>$invoice->plan_id,'plan_snapshot'=>$invoice->plan_snapshot,'expires_at'=>$base->addDays($plan['period_days']),
            'status'=>in_array($sub?->status,['suspended','inactive'],true) ? $sub->status : 'active','version'=>($sub->version ?? 0)+1,'updated_at'=>now()];
        if ($sub) DB::table('organization_subscriptions')->where('organization_id',$org->id)->update($values);
        else DB::table('organization_subscriptions')->insert(['organization_id'=>$org->id,'created_at'=>now()]+$values);
        DB::table('billing_invoices')->where('id',$invoice->id)->update(['status'=>'paid','paid_at'=>now(),'version'=>$invoice->version+1,'updated_at'=>now()]);
        if ($signup) {
            DB::table('organization_signups')->where('id',$signup->id)->update(['status'=>'paid','paid_at'=>now(),'updated_at'=>now()]);
            if ($org->status === 'pending_payment') DB::table('organizations')->where('id',$org->id)->update(['status'=>'active','version'=>$org->version+1,'updated_at'=>now()]);
        }
        app(\App\Services\Mail\NotificationEmails::class)->organization($sub?->expires_at ? 'subscription.renewed' : 'subscription.started',$invoice->id,$org->id,
            $sub?->expires_at ? 'Your plan has been renewed' : 'Welcome to your CarLink plan','Payment was confirmed and the purchased subscription period has been recorded.',
            ['Invoice'=>$invoice->number,'Plan'=>$plan['name'],'Amount'=>sprintf('%s %d.%02d',$invoice->currency,intdiv($invoice->amount_minor,100),$invoice->amount_minor%100),
             'Period ends'=>CarbonImmutable::parse($values['expires_at'])->setTimezone($org->timezone)->format('d M Y, H:i P').' ('.$org->timezone.')','Access status'=>$values['status']],
            ['subscription.read'],['subscription_required'=>false], 'Renewal does not override a suspended or inactive subscription.');
    }
}
