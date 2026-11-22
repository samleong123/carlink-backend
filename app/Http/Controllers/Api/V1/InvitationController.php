<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Membership;
use App\Models\User;
use App\Services\Mail\DurableMail;
use App\Services\Permissions;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvitationController
{
    private function authorize(Request $request): void { abort_unless(app(Permissions::class)->allows($request->attributes->get('membership'),'members.manage'),403); }
    public function index(Request $request, string $organization)
    {
        $this->authorize($request); $request->validate(['per_page'=>'nullable|integer|min:1|max:100','cursor'=>'nullable|string|max:512']);
        $page=DB::table('organization_invitations')->where('organization_id',$organization)->orderBy('id')->cursorPaginate((int)$request->input('per_page',50),['id','email_normalized','role_code','expires_at','accepted_at','revoked_at','created_at']);
        return ApiResponse::success($request,['items'=>$page->items(),'pagination'=>['next_cursor'=>$page->nextCursor()?->encode(),'per_page'=>$page->perPage()]],'Invitations Retrieved');
    }
    public function create(Request $request, string $organization)
    {
        $this->authorize($request);
        $v=$request->validate(['email'=>'required|email|max:255','role'=>['required','string',Rule::in(['admin','manager','viewer','safety_officer','maintenance_officer','driver','support','module_vehicle','module_driver','module_dashboard','module_compliance','module_coverage'])]]);
        $key=$request->header('Idempotency-Key');
        if (!is_string($key) || strlen($key)<8 || strlen($key)>128) throw ValidationException::withMessages(['Idempotency-Key'=>['An 8–128 character key is required.']]);
        abort_unless(DurableMail::deliveryEnabled(),503);
        $email=mb_strtolower(trim($v['email'])); $hash=hash('sha256',$email.'|'.$v['role']);
        $id=DB::transaction(function () use ($request,$organization,$key,$v,$email,$hash) {
            DB::table('organizations')->where('id',$organization)->lockForUpdate()->first();
            $old=DB::table('organization_invitations')->where('organization_id',$organization)->where('request_key_hash',hash('sha256',$key))->first();
            if ($old) { abort_unless(hash_equals($old->request_hash,$hash),409); return $old->id; }
            $user=User::where('email_normalized',$email)->first();
            abort_if($user && ($user->status !== 'active' || Membership::where('organization_id',$organization)->where('user_id',$user->id)->exists()),409);
            $pending=DB::table('organization_invitations')->where('organization_id',$organization)->where('email_normalized',$email)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at','>',now())->exists();
            abort_if($pending,409);
            $user ??= User::create(['email_normalized'=>$email,'display_name'=>strstr($email,'@',true),'status'=>'active']);
            $id=(string)Str::uuid(); $token=bin2hex(random_bytes(32)); $expires=now()->addDays(2);
            DB::table('organization_invitations')->insert(['id'=>$id,'organization_id'=>$organization,'email_normalized'=>$email,'role_code'=>$v['role'],'token_hash'=>hash('sha256',$token),
                'request_key_hash'=>hash('sha256',$key),'request_hash'=>$hash,'invited_by'=>$request->user()->id,'expires_at'=>$expires,'created_at'=>now(),'updated_at'=>now()]);
            $url=rtrim(config('app.frontend_url'),'/').'/invitations/confirm?token='.rawurlencode($token);
            $message=app(\App\Services\Mail\EmailTemplate::class)->render('You’re invited','Your fleet workspace is ready. Sign in with this email address, then explicitly accept the invitation.',
                ['Organization'=>DB::table('organizations')->where('id',$organization)->value('name'),'Access role'=>$v['role'],'Expires'=>'48 hours'],$url,'Review invitation','Opening the link does not accept the invitation. Only the invited mailbox can confirm.');
            app(DurableMail::class)->enqueue('invitation',$id,$user->id,$organization,'CarLink fleet invitation',$message['body'],$expires,$message['html']);
            return $id;
        });
        return ApiResponse::success($request,['id'=>$id],'Invitation Recorded','Delivery is queued, not confirmed.',201);
    }
    public function revoke(Request $request, string $organization, string $invitation)
    {
        $this->authorize($request);
        DB::transaction(function () use ($organization,$invitation) {
            DB::table('organizations')->where('id',$organization)->lockForUpdate()->first();
            $row=DB::table('organization_invitations')->where('organization_id',$organization)->where('id',$invitation)->lockForUpdate()->first() ?? abort(404);
            abort_if($row->accepted_at,409);
            DB::table('organization_invitations')->where('id',$row->id)->update(['revoked_at'=>$row->revoked_at ?? now(),'updated_at'=>now()]);
        });
        return ApiResponse::success($request,[],'Invitation Revoked');
    }
    public function accept(Request $request)
    {
        $v=$request->validate(['token'=>'required|string|size:64']);
        $id=DB::transaction(function () use ($request,$v) {
            $probe=DB::table('organization_invitations')->where('token_hash',hash('sha256',$v['token']))->first() ?? abort(422);
            $org=DB::table('organizations')->where('id',$probe->organization_id)->lockForUpdate()->first();
            $row=DB::table('organization_invitations')->where('id',$probe->id)->lockForUpdate()->first();
            abort_unless($org && $org->status === 'active' && !$row->accepted_at && !$row->revoked_at && now()->lt($row->expires_at),422);
            abort_unless($request->user()->status === 'active' && hash_equals($row->email_normalized,$request->user()->email_normalized),403);
            abort_if(Membership::where('organization_id',$org->id)->where('user_id',$request->user()->id)->exists(),409);
            $membership=Membership::create(['organization_id'=>$org->id,'user_id'=>$request->user()->id,'role'=>$row->role_code,'status'=>'active']);
            DB::table('organization_invitations')->where('id',$row->id)->update(['accepted_at'=>now(),'updated_at'=>now()]);
            app(\App\Services\Mail\NotificationEmails::class)->status('membership',(object)$membership->refresh()->getAttributes(),$membership->user_id,$org->id);
            $audit=app(\App\Services\ActionAudit::class);
            $audit->outbox($audit->event('invitation.accept','succeeded','http',array_merge($audit->context($request),['organization_id'=>$org->id,'invitation_id'=>$row->id,'membership_id'=>$membership->id])));
            return $membership->id;
        });
        return ApiResponse::success($request,['membership_id'=>$id],'Invitation Accepted');
    }
}
