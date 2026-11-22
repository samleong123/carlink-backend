<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Platform
{
    public function handle(Request $request, Closure $next, string $role='staff'): mixed
    {
        abort_unless($request->user()?->status === 'active',403);
        $staff=DB::table('saas_admins')->where('user_id',$request->user()->id)->where('status','active')->first();
        abort_unless($staff && ($role !== 'admin' || $staff->role === 'admin'),403);
        $request->attributes->set('platform_staff',$staff);
        $organization=$request->route('organization');
        if (is_string($organization) && !$request->isMethod('GET')) {
            abort_if(DB::table('organizations')->where('id',$organization)->where('status','pending_payment')->exists(),409,'Self-service signups require confirmed payment before platform changes.');
        }
        return $next($request);
    }
}
