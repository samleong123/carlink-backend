<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SignupCreation;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function requestSignupLink(Request $request)
    {
        $request->validate(['organization'=>'sometimes|required|array:name,timezone,default_locale,plan_id,plan_version,accept_unpaid_deletion']);
        $organization=$request->has('organization') ? app(SignupCreation::class)->validate($request->input('organization')) : null;
        if ($organization) { app(SignupCreation::class)->key($request->header('Idempotency-Key')); app(SignupCreation::class)->plan($organization); }
        return $this->requestLink($request,true,$organization);
    }
    public function requestLink(Request $request, bool $signup=false, ?array $organization=null)
    {
        $input = $request->validate(['email'=>'required|email|max:255', 'return_to'=>'nullable|string|max:255']);
        $returnTo = $input['return_to'] ?? ($signup ? '/signup' : '/console');
        if (!str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//') || str_contains($returnTo, '\\') || preg_match('/[\x00-\x1f\x7f]/', $returnTo)) {
            throw ValidationException::withMessages(['return_to'=>['Must be a relative path.']]);
        }
        $email = mb_strtolower(trim($input['email']));
        $limitKey='magic-email:'.hash('sha256',$email);
        if (RateLimiter::tooManyAttempts($limitKey,3) || RateLimiter::tooManyAttempts('magic-global',300)) {
            return ApiResponse::success($request, [], 'Login Link Requested', 'If the account is eligible, a sign-in link will be sent.', 202);
        }
        RateLimiter::hit($limitKey,60); RateLimiter::hit('magic-global',60);
        // Signup only establishes a mailbox identity; no organization or authority is created before confirmation.
        $canDeliverMail = \App\Services\Mail\DurableMail::deliveryEnabled();
        if ($signup && $canDeliverMail) {
            User::firstOrCreate(['email_normalized'=>$email],['display_name'=>$email,'status'=>'active']);
        }
        $user = User::where('email_normalized', $email)->where('status', 'active')->first();
        if ($user && $canDeliverMail) DB::transaction(function () use ($user,$email,$returnTo,$organization,$request) {
            $current=DB::table('users')->where('id',$user->id)->lockForUpdate()->first();
            if (!$current || $current->status !== 'active') return;
            $key=$organization ? app(SignupCreation::class)->key($request->header('Idempotency-Key')) : null;
            // Replaying a signup email request must not send another link or change its reviewed draft.
            if ($key && DB::table('organization_signup_intents')->where('user_id',$user->id)->where('request_key_hash',hash('sha256',$key))->exists()) return;
            $token = bin2hex(random_bytes(32));
            $id=(string)Str::uuid(); $expires=now()->addMinutes(10);
            DB::table('magic_link_tokens')->insert(['id'=>$id, 'user_id'=>$user->id, 'email_normalized'=>$email, 'token_hash'=>hash('sha256',$token), 'purpose'=>$organization ? 'signup' : 'login', 'expires_at'=>$expires, 'return_path'=>$returnTo, 'created_at'=>now(), 'updated_at'=>now()]);
            if ($organization) DB::table('organization_signup_intents')->insert(['token_id'=>$id,'user_id'=>$user->id,'request_key_hash'=>hash('sha256',$key),
                'request_hash'=>app(SignupCreation::class)->hash($organization),'encrypted_payload'=>Crypt::encryptString(json_encode(['organization'=>$organization,'key'=>$key],JSON_THROW_ON_ERROR)),
                'expires_at'=>$expires,'created_at'=>now(),'updated_at'=>now()]);
            $url = rtrim(config('app.frontend_url'), '/').'/auth/confirm?token='.rawurlencode($token).($organization ? '&flow=signup' : '');
            $details=['Expires'=>'10 minutes','Account'=>$email];
            if ($organization) {
                $plan=app(SignupCreation::class)->plan($organization);
                $details+=['Organization'=>$organization['name'],'Plan'=>$plan->name,'Amount'=>sprintf('%s %d.%02d',$plan->currency,intdiv($plan->price_minor,100),$plan->price_minor%100)];
            }
            $subject=$organization ? 'Confirm your CarLink organization' : 'CarLink sign-in link';
            $message=app(\App\Services\Mail\EmailTemplate::class)->render($organization ? 'Confirm your organization' : 'Your sign-in link',
                $organization ? 'Confirm this email to create your organization and invoice. Secure payment will open next; your organization is activated only after payment is confirmed.' : 'One secure link. No password to remember. Open CarLink and confirm to sign in.',
                $details,$url,$organization ? 'Review & confirm signup' : 'Sign in to CarLink',
                $organization ? 'The unpaid organization is permanently deleted 12 hours after creation. This link works once. If you did not request this signup, ignore this email.' : 'This link works once. If you did not request it, you can safely ignore this email. CarLink will never ask you to forward it.');
            app(\App\Services\Mail\DurableMail::class)->enqueue('login',$id,$user->id,null,$subject,$message['body'],$expires,$message['html']);
            $audit=app(\App\Services\ActionAudit::class); $audit->outbox($audit->event('auth.request_link','succeeded','http',['user_id'=>$user->id,'browser_absence_reason'=>'not_collected']));
        },3);
        return ApiResponse::success($request, [], 'Login Link Requested', 'If the account is eligible, a sign-in link will be sent.', 202);
    }
    public function consume(Request $request)
    {
        // Sanctum starts browser sessions only for configured first-party origins.
        // Reject stateless requests before consuming a token or invalidating its siblings.
        if (!$request->hasSession()) {
            return ApiResponse::error($request, 'Browser Session Required', 'Initialize a browser session through /sanctum/csrf-cookie and send its cookies from a configured first-party origin.', 419, ['error'=>['code'=>'CSRF_FAILED']]);
        }
        $input = $request->validate(['token'=>'required|string|size:64']);
        $result = DB::transaction(function () use ($input,$request) {
            // Serialize all links for a mailbox before locking a token, including sibling invalidation.
            $candidate=DB::table('magic_link_tokens')->where('token_hash',hash('sha256',$input['token']))->first();
            if (!$candidate) return null;
            $user = User::query()->whereKey($candidate->user_id)->where('status','active')->lockForUpdate()->first();
            if (!$user) return null;
            $link = DB::table('magic_link_tokens')->where('id',$candidate->id)->lockForUpdate()->first();
            if (!$link || $link->consumed_at || !in_array($link->purpose,['login','signup'],true) || now()->gte(\Illuminate\Support\Carbon::parse($link->expires_at))) { return null; }
            $intent=$link->purpose === 'signup' ? DB::table('organization_signup_intents')->where('token_id',$link->id)->where('user_id',$user->id)->lockForUpdate()->first() : null;
            if ($link->purpose === 'signup' && (!$intent || !$intent->encrypted_payload || now()->gte(\Illuminate\Support\Carbon::parse($intent->expires_at)))) return null;
            DB::table('magic_link_tokens')->where('id',$link->id)->update(['consumed_at'=>now(),'updated_at'=>now()]);
            DB::table('magic_link_tokens')->where('user_id',$user->id)->where('id','<>',$link->id)->whereNull('consumed_at')->update(['consumed_at'=>now(),'updated_at'=>now()]);
            $user->forceFill(['last_login_at'=>now(),'email_verified_at'=>$user->email_verified_at ?? now()])->save();
            $returnPath=$link->return_path;
            if ($intent) {
                $draft=json_decode(Crypt::decryptString($intent->encrypted_payload),true,32,JSON_THROW_ON_ERROR);
                $creation=app(SignupCreation::class); $organization=$creation->validate($draft['organization']);
                abort_unless(hash_equals($intent->request_hash,$creation->hash($organization)) && hash_equals($intent->request_key_hash,hash('sha256',$draft['key'])),409);
                [$created]=$creation->create($request,$user,$organization,$draft['key'],true);
                $returnPath='/signup?signup='.$created->id.'&checkout=1';
            }
            DB::table('organization_signup_intents')->where('user_id',$user->id)->whereIn('token_id',DB::table('magic_link_tokens')->where('user_id',$user->id)->whereNotNull('consumed_at')->select('id'))->update(['encrypted_payload'=>null,'updated_at'=>now()]);
            $emails=app(\App\Services\Mail\NotificationEmails::class);
            $identity=$request->attributes->get('identity_context',[]);
            $country=$identity['ip_country_display'] ?? $identity['ip_country'] ?? null;
            $emails->queue('account.login',$link->id,$user->id,null,'New sign-in to CarLink','Your email sign-in link was successfully confirmed.',
                ['IP address'=>$identity['ip_address'] ?? null,'IP country'=>$country,'IP source'=>$identity['ip_source'] ?? 'unavailable','Signed in at'=>$emails->localTime(now(),$user->id),
                 'User agent'=>$identity['user_agent_raw'] ?? null,'Browser'=>$identity['browser_name'] ?? null,'Platform'=>$identity['platform_name'] ?? null],[],null,
                'Browser details are client-reported, not proof of device identity. If this was not you, contact your account administrator immediately.');
            return [$user,$returnPath];
        },3);
        if (!$result) { return ApiResponse::error($request,'Login Failed','The sign-in link is invalid or expired.',422); }
        Auth::guard('web')->login($result[0]);
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        $request->session()->put('authenticated_at',now()->timestamp);
        $request->session()->put('email_link_verified_at',now()->timestamp);
        return ApiResponse::success($request, ['return_to'=>$result[1]], 'Login Success');
    }
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate(); $request->session()->regenerateToken();
        return ApiResponse::success($request, [], 'Logout Success');
    }
    public function me(Request $request)
    {
        abort_unless($request->user()->status === 'active', 403);
        $user = $request->user()->load('memberships.organization');
        $permissions = [];
        foreach ($user->memberships as $membership) $permissions[$membership->organization_id] = app(\App\Services\Permissions::class)->keys($membership);
        $platform=DB::table('saas_admins')->where('user_id',$user->id)->where('status','active')->first(['role','version']);
        return ApiResponse::success($request, ['user'=>$user, 'permissions'=>(object) $permissions,'platform'=>$platform], 'Profile Retrieved');
    }
}
