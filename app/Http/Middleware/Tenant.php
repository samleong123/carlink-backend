<?php
namespace App\Http\Middleware;

use App\Models\Membership;
use Closure;
use Illuminate\Http\Request;

class Tenant
{
    public function handle(Request $request, Closure $next): mixed
    {
        // A previously authenticated session must not retain tenant access after
        // the account itself has been disabled, even if its membership is active.
        abort_unless($request->user()?->status === 'active', 404);
        $org = $request->route('organization');
        $id = is_object($org) ? $org->id : $org;
        $membership = Membership::query()->where('organization_id', $id)->where('user_id', $request->user()?->id)->where('status', 'active')->whereHas('organization', fn ($q) => $q->whereIn('status', ['active','pending_payment']))->first();
        abort_unless($membership, 404);
        $request->attributes->set('membership', $membership);
        // Billing/account administration remain reachable to renew a lapsed subscription.
        $route=$request->route()?->uri() ?? '';
        $suffix=preg_replace('#^api/v1/organizations/\{organization\}/?#','',$route);
        if ($membership->organization->status === 'pending_payment') {
            app(\App\Services\OrganizationSignups::class)->requireOpen($id);
            $read=$request->isMethod('GET') && preg_match('#^(?:subscription|plans|invoices(?:/[^/]+)?|payments/[^/]+)?$#',$suffix);
            $checkout=$request->isMethod('POST') && preg_match('#^invoices/[^/]+/pay$#',$suffix);
            abort_unless($read || $checkout,402,'Complete signup payment before using organization operations.');
        }
        $billing=preg_match('#^(?:subscription|plans|invoices|members|invitations|settings|ownership-transfers|request-logs|audit-logs|payments)(?:/|$)#',$suffix) || $suffix === '';
        if (!$billing) app(\App\Services\Entitlements::class)->requireActive($id);
        return $next($request);
    }
}
