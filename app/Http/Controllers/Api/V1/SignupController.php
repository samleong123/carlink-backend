<?php
namespace App\Http\Controllers\Api\V1;

use App\Services\SignupCreation;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt,DB};

class SignupController
{
    public function plans(Request $r)
    {
        $items=DB::table('subscription_plans')->where('status','active')->where('currency','MYR')->where('price_minor','>',0)
            ->orderBy('price_minor')->orderBy('id')->limit(200)->get(['id','name','price_minor','currency','period_days','vehicle_limit',
                'driver_limit','retention_days','cellular_enabled','safety_enabled','telemetry_min_seconds','version']);
        return ApiResponse::success($r,['items'=>$items,'payment_window_hours'=>12],'Public Plans Retrieved');
    }
    private function active(Request $r): void { abort_unless($r->user()?->status === 'active',403); }
    public function create(Request $r)
    {
        $this->active($r);
        $creation=app(SignupCreation::class);
        $result=$creation->create($r,$r->user(),$creation->validate($r->all()),$creation->key($r->header('Idempotency-Key')));
        return ApiResponse::success($r,$this->data($result[0]),'Organization Signup Retrieved','Payment confirmation is required within 12 hours. Unpaid organizations are permanently deleted; financial/audit evidence is retained.', $result[1] ? 201 : 200);
    }
    private function data(?object $signup): array
    {
        if (!$signup) return ['signup'=>null,'invoice'=>null];
        $safe=(array)$signup; unset($safe['request_key_hash'],$safe['request_hash']);
        foreach (['payment_due_at','paid_at','deleted_at','created_at','updated_at'] as $field) {
            if ($safe[$field] ?? null) $safe[$field]=\Carbon\CarbonImmutable::parse($safe[$field])->toIso8601String();
        }
        $safe['deadline_passed']=$signup->status === 'pending' && \Carbon\CarbonImmutable::parse($signup->payment_due_at)->lessThanOrEqualTo(now());
        $invoice=$signup->status === 'deleted' ? null : DB::table('billing_invoices')->where('organization_id',$signup->organization_id)->where('id',$signup->invoice_id)->first();
        if ($invoice) $invoice->plan_snapshot=json_decode($invoice->plan_snapshot,true,32,JSON_THROW_ON_ERROR);
        return ['signup'=>$safe,'invoice'=>$invoice];
    }
    public function latest(Request $r)
    {
        $this->active($r);
        $signup=DB::table('organization_signups')->where('user_id',$r->user()->id)->orderByDesc('created_at')->orderByDesc('id')->first();
        return ApiResponse::success($r,$this->data($signup),'Latest Organization Signup Retrieved');
    }
    public function show(Request $r, string $signup)
    {
        $this->active($r);
        $row=DB::table('organization_signups')->where('user_id',$r->user()->id)->where('id',$signup)->first() ?? abort(404);
        return ApiResponse::success($r,$this->data($row),'Organization Signup Retrieved');
    }
    public function archives(Request $r)
    {
        $v=$r->validate(['per_page'=>'nullable|integer|min:1|max:100','cursor'=>'nullable|string|max:512']);
        $p=DB::table('organization_signups')->where('status','deleted')->select(['id','user_id','organization_id','invoice_id','payment_due_at','deleted_at','created_at'])
            ->selectSub(DB::table('signup_payment_archives')->selectRaw('COUNT(*)')->whereColumn('signup_id','organization_signups.id')->where('reconciliation_required',true),'payments_requiring_reconciliation')
            ->orderBy('id')->cursorPaginate($v['per_page'] ?? 50);
        return ApiResponse::success($r,['items'=>$p->items(),'pagination'=>['next_cursor'=>$p->nextCursor()?->encode()]],'Signup Archives Retrieved');
    }
    public function archive(Request $r, string $signup)
    {
        $row=DB::table('signup_financial_archives')->where('signup_id',$signup)->first() ?? abort(404);
        $payments=DB::table('signup_payment_archives')->where('signup_id',$signup)->get()->map(function ($p) { $v=(array)$p; unset($v['callback_token_hash']); return $v; });
        $snapshot=json_decode(Crypt::decryptString($row->encrypted_snapshot),true,64,JSON_THROW_ON_ERROR);
        foreach ($snapshot['payments'] as &$p) unset($p['callback_token_hash']); unset($p);
        return ApiResponse::success($r,['snapshot'=>$snapshot,'payments'=>$payments,'events'=>DB::table('signup_archive_events')->where('signup_id',$signup)->orderBy('created_at')->get()], 'Signup Financial Archive Retrieved');
    }
}
