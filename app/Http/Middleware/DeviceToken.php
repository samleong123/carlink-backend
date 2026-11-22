<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Edge-only routes. Never confers human API or tenant membership authority. */
class DeviceToken
{
    public function handle(Request $r, Closure $next): mixed
    {
        $d=DB::table('devices')->where('id',$r->route('device'))->where('status','active')->first();
        $token=$r->bearerToken();
        abort_unless($d && $d->auth_token_encrypted && is_string($token) && strlen($token)===64,401);
        try { $valid=hash_equals(Crypt::decryptString($d->auth_token_encrypted),$token); } catch (\Throwable $e) { $valid=false; }
        abort_unless($valid,401);
        abort_unless(DB::table('organizations')->where('id',$d->organization_id)->where('status','active')->exists(),403);
        app(\App\Services\Entitlements::class)->requireActive($d->organization_id);
        abort_unless(DB::table('device_installations')->where('organization_id',$d->organization_id)->where('device_id',$d->id)->whereNull('effective_to')->exists(),403);
        $r->attributes->set('authenticated_device',$d);
        return $next($r);
    }
}
