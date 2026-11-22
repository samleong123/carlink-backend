<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\Entitlements;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlatformController
{
    public function plans(Request $r) { return ApiResponse::success($r,['items'=>DB::table('subscription_plans')->orderBy('id')->limit(200)->get()],'Plans Retrieved'); }
    private function rules(bool $update): array
    {
        $p=$update ? 'sometimes' : 'required';
        return ['name'=>"$p|string|max:100",'price_minor'=>"$p|integer|min:1|max:1000000000",'currency'=>"$p|in:MYR",'period_days'=>"$p|integer|min:1|max:365",
            'vehicle_limit'=>"$p|integer|min:1|max:100000",'driver_limit'=>"$p|integer|min:1|max:100000",'retention_days'=>"$p|integer|in:30,90,180,365",
            'cellular_enabled'=>"$p|boolean",'safety_enabled'=>"$p|boolean",'telemetry_min_seconds'=>"$p|integer|min:2|max:30",'status'=>'sometimes|in:active,inactive'];
    }
    public function createPlan(Request $r)
    {
        $v=$r->validate($this->rules(false)); $id=(string)Str::uuid();
        DB::table('subscription_plans')->insert(['id'=>$id,'created_at'=>now(),'updated_at'=>now()]+$v);
        return ApiResponse::success($r,['item'=>DB::table('subscription_plans')->find($id)],'Plan Created','',201);
    }
    public function updatePlan(Request $r, string $plan)
    {
        $v=$r->validate($this->rules(true)+['version'=>'required|integer|min:1']);
        $old=DB::table('subscription_plans')->where('id',$plan)->lockForUpdate()->first() ?? abort(404);
        abort_if((int)$old->version !== $v['version'],409);
        DB::table('subscription_plans')->where('id',$plan)->update(array_replace($v,['version'=>$old->version+1,'updated_at'=>now()]));
        return ApiResponse::success($r,['item'=>DB::table('subscription_plans')->find($plan),'existing_subscription_snapshots_unchanged'=>true],'Plan Updated');
    }
    public function subscribers(Request $r)
    {
        $v=$r->validate(['status'=>'nullable|in:pending,active,suspended,inactive','per_page'=>'nullable|integer|min:1|max:200','cursor'=>'nullable|string|max:512']);
        $p=DB::table('organization_subscriptions as s')->join('organizations as o','o.id','=','s.organization_id')->when($v['status'] ?? null,fn($q,$status)=>$q->where('s.status',$status))
            ->orderBy('s.organization_id')->cursorPaginate($v['per_page'] ?? 50,['s.*','o.name as organization_name']);
        foreach ($p->items() as $row) { $row->plan_snapshot=json_decode($row->plan_snapshot ?? '{}'); $row->access_active=app(Entitlements::class)->active($row->organization_id); }
        return ApiResponse::success($r,['items'=>$p->items(),'pagination'=>['next_cursor'=>$p->nextCursor()?->encode()]],'Subscribers Retrieved');
    }
    public function subscription(Request $r, string $organization)
    {
        $v=$r->validate(['version'=>'required|integer|min:1','status'=>'required|in:active,suspended,inactive']);
        DB::table('organizations')->where('id',$organization)->lockForUpdate()->first() ?? abort(404);
        $sub=DB::table('organization_subscriptions')->where('organization_id',$organization)->lockForUpdate()->first() ?? abort(404);
        abort_if((int)$sub->version !== $v['version'],409);
        if ($v['status'] === 'active') abort_unless($sub->expires_at && CarbonImmutable::parse($sub->expires_at)->isFuture(),409,'Activation requires a paid, unexpired period.');
        DB::table('organization_subscriptions')->where('organization_id',$organization)->update(['status'=>$v['status'],'version'=>$sub->version+1,'updated_at'=>now()]);
        if ($sub->status !== $v['status']) app(\App\Services\Mail\NotificationEmails::class)->organization('subscription.status',$organization.':'.($sub->version+1),$organization,'Subscription '.$v['status'],
            'Your organization subscription status has changed. Paid periods and access status are managed separately.',['Status'=>$v['status']],['subscription.read'],['subscription_required'=>false,'resource_type'=>'subscription','resource_id'=>$organization,'resource_version'=>$sub->version+1]);
        return ApiResponse::success($r,['version'=>$sub->version+1],'Subscription Updated');
    }
    public function admins(Request $r) { return ApiResponse::success($r,['items'=>DB::table('saas_admins as s')->join('users as u','u.id','=','s.user_id')->select('s.*','u.email_normalized','u.display_name')->orderBy('s.user_id')->limit(200)->get()],'SaaS Staff Retrieved'); }
    private function recent(Request $r): void
    {
        $time=$r->hasSession() ? $r->session()->get('email_link_verified_at') : null;
        abort_unless(is_int($time) && $time <= now()->timestamp && $time > now()->subMinutes(10)->timestamp,403,'Recent email-link verification required.');
    }
    public function addAdmin(Request $r)
    {
        $this->recent($r); $v=$r->validate(['email'=>'required|email|max:255','display_name'=>'required|string|max:160','role'=>'required|in:admin,technician']);
        DB::table('platform_mutex')->where('id',1)->lockForUpdate()->first();
        $u=User::firstOrCreate(['email_normalized'=>strtolower(trim($v['email']))],['display_name'=>$v['display_name'],'status'=>'active']);
        abort_if(DB::table('saas_admins')->where('user_id',$u->id)->exists(),409);
        DB::table('saas_admins')->insert(['user_id'=>$u->id,'role'=>$v['role'],'status'=>'active','version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        app(\App\Services\Mail\NotificationEmails::class)->status('platform',DB::table('saas_admins')->where('user_id',$u->id)->first(),$u->id,null);
        return ApiResponse::success($r,['user_id'=>$u->id],'SaaS Staff Created','The staff member must request their own email sign-in link.',201);
    }
    public function updateAdmin(Request $r, string $user)
    {
        $this->recent($r); $v=$r->validate(['version'=>'required|integer|min:1','status'=>'required|in:active,suspended']);
        DB::table('platform_mutex')->where('id',1)->lockForUpdate()->first();
        $old=DB::table('saas_admins')->where('user_id',$user)->lockForUpdate()->first() ?? abort(404);
        abort_if((int)$old->version !== $v['version'],409);
        if ($old->role === 'admin' && $v['status'] !== 'active') abort_if(DB::table('saas_admins as s')->join('users as u','u.id','=','s.user_id')->where('s.role','admin')->where('s.status','active')->where('u.status','active')->count() <= 1,409,'Cannot suspend the last SaaS admin.');
        DB::table('saas_admins')->where('user_id',$user)->update(['status'=>$v['status'],'version'=>$old->version+1,'updated_at'=>now()]);
        if ($old->status !== $v['status']) app(\App\Services\Mail\NotificationEmails::class)->status('platform',DB::table('saas_admins')->where('user_id',$user)->first(),$user,null);
        return ApiResponse::success($r,['version'=>$old->version+1],'SaaS Staff Updated');
    }
    public function report(Request $r)
    {
        $since30d = now()->subDays(30);

        $subscriptionsByStatus = array_replace(
            ['active' => 0, 'pending' => 0, 'suspended' => 0, 'inactive' => 0],
            DB::table('organization_subscriptions')
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->map(fn($c) => (int)$c)
                ->all()
        );

        $devicesByStatus = array_replace(
            ['registered' => 0, 'active' => 0, 'suspended' => 0, 'decommissioned' => 0],
            DB::table('devices')
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->map(fn($c) => (int)$c)
                ->all()
        );

        $paidInvoicesCount = (int) DB::table('billing_invoices')
            ->where('status', 'paid')
            ->count();

        $revenue30dMinor = (int) DB::table('billing_invoices')
            ->where('status', 'paid')
            ->where('currency', 'MYR')
            ->where(function ($q) use ($since30d) {
                $q->where('paid_at', '>=', $since30d)
                  ->orWhere(function ($q2) use ($since30d) {
                      $q2->whereNull('paid_at')->where('created_at', '>=', $since30d);
                  });
            })
            ->sum('amount_minor');

        try {
            $telemetrySamples24h = (int) DB::table('telemetry_samples')
                ->where('occurred_at', '>=', now()->subHours(24))
                ->count();
        } catch (\Throwable) {
            $telemetrySamples24h = 0;
        }

        $requests24h = null;
        $requests7d = null;
        $errorRequests24h = null;
        $serverErrors24h = null;
        $avgLatencyMs24h = null;
        $dailyRequests7d = [];
        $topEndpoints24h = [];

        try {
            $logConn = DB::connection('logging');
            $now = CarbonImmutable::now();
            $since24h = $now->subHours(24);
            $since7d = $now->subDays(7);

            $requests24h = (int) $logConn->table('http_request_logs')
                ->where('started_at', '>=', $since24h)
                ->count();

            $requests7d = (int) $logConn->table('http_request_logs')
                ->where('started_at', '>=', $since7d)
                ->count();

            $errorRequests24h = (int) $logConn->table('http_request_logs')
                ->where('started_at', '>=', $since24h)
                ->where('http_status', '>=', 400)
                ->count();

            $serverErrors24h = (int) $logConn->table('http_request_logs')
                ->where('started_at', '>=', $since24h)
                ->where('http_status', '>=', 500)
                ->count();

            $avg = $logConn->table('http_request_logs')
                ->where('started_at', '>=', $since24h)
                ->whereNotNull('duration_ms')
                ->avg('duration_ms');
            $avgLatencyMs24h = $avg !== null ? round((float) $avg, 1) : null;

            $dailyMap = [];
            for ($i = 6; $i >= 0; $i--) {
                $dateStr = $now->subDays($i)->format('Y-m-d');
                $dailyMap[$dateStr] = ['date' => $dateStr, 'count' => 0, 'errors' => 0];
            }

            $dailyRows = $logConn->table('http_request_logs')
                ->where('started_at', '>=', $now->subDays(6)->startOfDay())
                ->select(
                    DB::raw('date(started_at) as log_date'),
                    DB::raw('count(*) as count'),
                    DB::raw('sum(case when http_status >= 400 then 1 else 0 end) as errors')
                )
                ->groupBy(DB::raw('date(started_at)'))
                ->get();

            foreach ($dailyRows as $row) {
                $d = (string) $row->log_date;
                if (isset($dailyMap[$d])) {
                    $dailyMap[$d]['count'] = (int) $row->count;
                    $dailyMap[$d]['errors'] = (int) $row->errors;
                }
            }
            $dailyRequests7d = array_values($dailyMap);

            $topEndpoints24h = $logConn->table('http_request_logs')
                ->where('started_at', '>=', $since24h)
                ->whereNotNull('route')
                ->select('method', 'route', DB::raw('count(*) as count'))
                ->groupBy('method', 'route')
                ->orderByDesc('count')
                ->limit(10)
                ->get()
                ->map(fn($row) => [
                    'method' => strtoupper((string) ($row->method ?? 'GET')),
                    'path' => (string) ($row->route ?? ''),
                    'route' => (string) ($row->route ?? ''),
                    'count' => (int) $row->count,
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            $requests24h = null;
            $requests7d = null;
            $errorRequests24h = null;
            $serverErrors24h = null;
            $avgLatencyMs24h = null;
            $dailyRequests7d = [];
            $topEndpoints24h = [];
        }

        return ApiResponse::success($r, [
            'as_of' => now()->toIso8601String(),
            'organizations' => DB::table('organizations')->count(),
            'active_paid_subscriptions' => DB::table('organization_subscriptions')->where('status', 'active')->where('expires_at', '>', now())->count(),
            'registered_devices' => DB::table('devices')->count(),
            'activated_devices' => DB::table('devices')->where('status', 'active')->count(),
            'paid_invoice_total_minor' => (int) DB::table('billing_invoices')->where('status', 'paid')->where('currency', 'MYR')->sum('amount_minor'),
            'currency' => 'MYR',

            'total_users' => (int) DB::table('users')->count(),
            'total_vehicles' => (int) DB::table('vehicles')->where('status', '<>', 'archived')->count(),
            'total_drivers' => (int) DB::table('drivers')->count(),
            'saas_staff_count' => (int) DB::table('saas_admins')->count(),
            'subscriptions_by_status' => $subscriptionsByStatus,
            'devices_by_status' => $devicesByStatus,
            'paid_invoices_count' => $paidInvoicesCount,
            'revenue_30d_minor' => $revenue30dMinor,
            'telemetry_samples_24h' => $telemetrySamples24h,

            'requests_24h' => $requests24h,
            'requests_7d' => $requests7d,
            'error_requests_24h' => $errorRequests24h,
            'server_errors_24h' => $serverErrors24h,
            'avg_latency_ms_24h' => $avgLatencyMs24h,
            'daily_requests_7d' => $dailyRequests7d,
            'top_endpoints_24h' => $topEndpoints24h,
        ], 'Platform Analytics Retrieved');
    }
    public function logs(Request $r)
    {
        $v=$r->validate(['from'=>'required|date','to'=>'required|date|after:from','per_page'=>'nullable|integer|min:1|max:200','cursor'=>'nullable|string|max:512']);
        $from=CarbonImmutable::parse($v['from'])->utc(); $to=CarbonImmutable::parse($v['to'])->utc(); abort_if($from->diffInDays($to)>31,422);
        try {
            $p=DB::connection('logging')->table('http_request_logs')->where('started_at','>=',$from)->where('started_at','<',$to)->orderBy('started_at')->orderBy('request_id')->cursorPaginate($v['per_page'] ?? 50);
            // Global request metadata, not tenant business data or bodies/credentials.
            $items=array_map(fn($x)=>LogController::formatRequestLog($x), $p->items());
        } catch (\Throwable $e) { return ApiResponse::error($r,'Logs Unavailable','Logging database unavailable.',503); }
        return ApiResponse::success($r,['items'=>$items,'pagination'=>['next_cursor'=>$p->nextCursor()?->encode()]],'Global API Logs Retrieved');
    }
}
