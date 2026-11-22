<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Enforces an absolute lifetime independently of sliding session inactivity. */
class ExpireAbsoluteSession
{
    public function handle(Request $request, Closure $next): mixed
    {
        // Stateless requests, including devices and gateway callbacks, do not
        // establish a browser session and must not be affected by this policy.
        if (!$request->hasSession() || !Auth::guard('web')->check()) {
            return $next($request);
        }

        $authenticatedAt = $request->session()->get('authenticated_at');
        $now = now()->timestamp;
        $lifetime = max(1, (int) config('session.absolute_lifetime', 720)) * 60;
        if (!is_int($authenticatedAt) || $authenticatedAt > $now || $now - $authenticatedAt >= $lifetime) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            // Sanctum can retain a previously resolved user in a long-running
            // worker or a multi-request test; force it to resolve again.
            Auth::forgetGuards();
        }

        // Route authentication will reject expired sessions; public login and
        // health routes remain usable so an expired session can be renewed.
        return $next($request);
    }
}
