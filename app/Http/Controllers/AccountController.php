<?php
namespace App\Http\Controllers;
use App\Services\Mail\NotificationEmails;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountController
{
    public function update(Request $r)
    {
        abort_unless($r->user()->status==='active',403);
        abort_if(array_diff(array_keys($r->all()),['version','display_name','locale','timezone']),422,'Only personal display name, locale and timezone may be updated here.');
        $v=$r->validate(['version'=>'required|integer|min:1','display_name'=>'sometimes|required|string|max:160','locale'=>'sometimes|in:en,ms,zh','timezone'=>['sometimes',Rule::in(timezone_identifiers_list())]]);
        return DB::transaction(function() use($r,$v) {
            $user=DB::table('users')->where('id',$r->user()->id)->where('status','active')->lockForUpdate()->first() ?? abort(403);
            abort_unless((int)$user->version===(int)$v['version'],409);
            $changes=array_filter(array_diff_key($v,['version'=>true]),fn($value,$key)=>$value!==$user->$key,ARRAY_FILTER_USE_BOTH);
            if (!$changes) return ApiResponse::success($r,['version'=>$user->version],'Account Details Unchanged');
            DB::table('users')->where('id',$user->id)->update($changes+['version'=>$user->version+1,'updated_at'=>now()]);
            $emails=app(NotificationEmails::class);
            $emails->queue('account.details',$user->id.':'.($user->version+1),$user->id,null,'Your account details were updated','Your personal CarLink account settings have changed.',
                ['Changed fields'=>implode(', ',array_keys($changes)),'Updated at'=>$emails->localTime(now(),$user->id)],[],null,'If this was not you, contact your administrator. Your sign-in email address was not changed.');
            $audit=app(\App\Services\ActionAudit::class); $audit->outbox($audit->event('account.details.update','succeeded','http',$audit->context($r)));
            return ApiResponse::success($r,['version'=>$user->version+1],'Account Details Updated');
        });
    }
}
