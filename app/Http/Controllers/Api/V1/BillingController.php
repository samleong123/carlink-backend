<?php
namespace App\Http\Controllers\Api\V1;
use App\Services\Entitlements;
use App\Services\PaymentAudit;
use App\Services\Permissions;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingController
{
    private function allow(Request $r, string $key): void { if ($r->attributes->get('platform_staff')?->role === 'admin') return; abort_unless(app(Permissions::class)->allows($r->attributes->get('membership'),$key),403); }
    public function plans(Request $r, string $organization) { $this->allow($r,'subscription.read'); return ApiResponse::success($r,['items'=>DB::table('subscription_plans')->where('status','active')->orderBy('id')->limit(200)->get()],'Available Plans Retrieved'); }
    public function show(Request $r, string $organization)
    {
        $this->allow($r,'subscription.read'); $s=app(Entitlements::class)->subscription($organization);
        if ($s) $s->plan_snapshot=json_decode($s->plan_snapshot ?? '{}');
        return ApiResponse::success($r,['subscription'=>$s,'access_active'=>app(Entitlements::class)->active($organization),
            'usage'=>['vehicles'=>DB::table('vehicles')->where('organization_id',$organization)->where('status','<>','archived')->count(),'drivers'=>DB::table('drivers')->where('organization_id',$organization)->count()]],'Subscription Retrieved');
    }
    public function invoices(Request $r, string $organization)
    {
        $this->allow($r,'subscription.read');
        $v=$r->validate(['per_page'=>'nullable|integer|min:1|max:100','cursor'=>'nullable|string|max:512']);
        $p=DB::table('billing_invoices')->where('organization_id',$organization)->orderBy('id')->cursorPaginate($v['per_page'] ?? 50);
        return ApiResponse::success($r,['items'=>$p->items(),'pagination'=>['next_cursor'=>$p->nextCursor()?->encode()]],'Invoices Retrieved');
    }
    public function invoice(Request $r, string $organization, string $invoice)
    {
        $this->allow($r,'subscription.read'); return ApiResponse::success($r,['invoice'=>DB::table('billing_invoices')->where('organization_id',$organization)->where('id',$invoice)->first() ?? abort(404)],'Invoice Retrieved');
    }
    public function issue(Request $r, string $organization)
    {
        if (!$r->attributes->has('platform_staff')) $this->allow($r,'subscription.manage');
        $v=$r->validate(['plan_id'=>'required|uuid']);
        $id=DB::transaction(function () use ($r,$organization,$v) {
            DB::table('organizations')->where('id',$organization)->where('status','active')->lockForUpdate()->first() ?? abort(404);
            $plan=DB::table('subscription_plans')->where('id',$v['plan_id'])->where('status','active')->lockForUpdate()->first() ?? abort(404);
            abort_if(DB::table('vehicles')->where('organization_id',$organization)->where('status','<>','archived')->count()>$plan->vehicle_limit || DB::table('drivers')->where('organization_id',$organization)->count()>$plan->driver_limit,409,'Archive vehicles or choose a plan with sufficient limits.');
            abort_if(DB::table('billing_invoices')->where('organization_id',$organization)->where('status','issued')->exists(),409,'An outstanding invoice already exists. Reconcile or void it before another request.');
            $id=(string)Str::uuid();
            DB::table('billing_invoices')->insert(['id'=>$id,'number'=>'CL-'.strtoupper(str_replace('-','',$id)),'organization_id'=>$organization,'plan_id'=>$plan->id,'plan_snapshot'=>json_encode($plan,JSON_THROW_ON_ERROR),
                'amount_minor'=>$plan->price_minor,'currency'=>$plan->currency,'issued_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            $audit=app(\App\Services\ActionAudit::class); $audit->outbox($audit->event('billing.invoice.issue','succeeded','http',array_replace($audit->context($r),['organization_id'=>$organization,'invoice_id'=>$id])));
            app(\App\Services\Mail\NotificationEmails::class)->organization('invoice.issued',$id,$organization,'Your subscription invoice is ready','Review and pay this invoice in CarLink. An invoice alone does not activate a subscription.',
                ['Invoice'=>'CL-'.strtoupper(str_replace('-','',$id)),'Plan'=>$plan->name,'Amount'=>sprintf('%s %d.%02d',$plan->currency,intdiv($plan->price_minor,100),$plan->price_minor%100)],['subscription.read'],['subscription_required'=>false,'resource_type'=>'invoice','resource_id'=>$id,'resource_version'=>1]);
            return $id;
        });
        return ApiResponse::success($r,['invoice'=>DB::table('billing_invoices')->find($id)],'Invoice Issued','Issuing an invoice does not activate a subscription.',201);
    }
    public function pay(Request $r, string $organization, string $invoice)
    {
        $this->allow($r,'payments.manage');
        $row=DB::table('billing_invoices')->where('organization_id',$organization)->where('id',$invoice)->first() ?? abort(404);
        abort_unless($row->status === 'issued' && !$row->payment_id,409,'Reconcile the existing payment before retrying.');
        $org=DB::table('organizations')->find($organization) ?? abort(404);
        $r->merge(['payee_name'=>$org->name,'transaction_details'=>'Subscription invoice '.$row->number,'amount'=>sprintf('%d.%02d',intdiv($row->amount_minor,100),$row->amount_minor%100)]);
        $r->attributes->set('billing_invoice_id',$invoice);
        return app(PaymentController::class)->create($r,$organization,app(PaymentAudit::class));
    }
    public function voidInvoice(Request $r, string $organization, string $invoice)
    {
        $v=$r->validate(['version'=>'required|integer|min:1']);
        return DB::transaction(function () use ($r,$organization,$invoice,$v) {
            DB::table('organizations')->where('id',$organization)->lockForUpdate()->first() ?? abort(404);
            $row=DB::table('billing_invoices')->where('organization_id',$organization)->where('id',$invoice)->lockForUpdate()->first() ?? abort(404);
            // Permanent provider checkouts cannot be cancelled by a local void.
            abort_unless($row->status === 'issued' && !$row->payment_id && (int)$row->version === $v['version'],409);
            DB::table('billing_invoices')->where('id',$invoice)->update(['status'=>'void','version'=>$row->version+1,'updated_at'=>now()]);
            $audit=app(\App\Services\ActionAudit::class); $audit->outbox($audit->event('billing.invoice.void','succeeded','http',array_replace($audit->context($r),['organization_id'=>$organization,'invoice_id'=>$invoice])));
            return ApiResponse::success($r,['version'=>$row->version+1],'Invoice Voided');
        });
    }
}
