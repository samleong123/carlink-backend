<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Membership;
use App\Services\Permissions;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TenantAdministrationController
{
    private function authorize(Request $request, string $permission): void
    {
        abort_unless(app(Permissions::class)->allows($request->attributes->get('membership'),$permission),403);
    }
    private function recent(Request $request): void
    {
        $verified = $request->hasSession() ? $request->session()->get('email_link_verified_at') : null;
        abort_unless(is_int($verified) && $verified <= now()->timestamp && $verified > now()->subMinutes(10)->timestamp,403,'Recent email-link verification required.');
    }
    public function updateOrganization(Request $request, string $organization)
    {
        $this->authorize($request,'organizations.write');
        $data = $request->validate(['version'=>'required|integer|min:1','name'=>'sometimes|string|max:160','contact_email'=>'sometimes|nullable|email|max:255','timezone'=>['sometimes',Rule::in(timezone_identifiers_list())],'default_locale'=>'sometimes|in:en,ms,zh','country_code'=>'sometimes|nullable|regex:/^[A-Z]{2}$/']);
        $row = DB::table('organizations')->where('id',$organization)->lockForUpdate()->first();
        abort_if((int)$row->version !== (int)$data['version'],409);
        DB::table('organizations')->where('id',$organization)->update(array_replace($data,['version'=>$row->version+1,'updated_at'=>now()]));
        return ApiResponse::success($request,['version'=>$row->version+1],'Organization Updated');
    }
    public function settings(Request $request, string $organization)
    {
        $this->authorize($request,'settings.read');
        $row = DB::table('organization_settings')->where('organization_id',$organization)->first();
        return ApiResponse::success($request,['version'=>$row->version ?? 1,'settings'=>$row ? json_decode($row->settings) : (object)[]],'Settings Retrieved');
    }
    public function updateSettings(Request $request, string $organization)
    {
        $this->authorize($request,'settings.write');
        $data = $request->validate(['version'=>'required|integer|min:1','settings'=>'required|array:device_offline_after_s,unidentified_driving_alert_after_s,notification_locales,telemetry_interval_seconds,harsh_braking_ms2,rapid_acceleration_ms2,maf_air_fuel_ratio,maf_fuel_density_g_per_litre,co2_petrol_kg_per_litre,co2_diesel_kg_per_litre,email_digest_hour,email_weekly_iso_day,email_subscription_warning_days,email_organization_daily,email_driver_daily,email_driver_weekly,email_score_changes',
            'settings.email_digest_hour'=>'sometimes|integer|min:0|max:23','settings.email_weekly_iso_day'=>'sometimes|integer|min:1|max:7','settings.email_subscription_warning_days'=>'sometimes|integer|min:0|max:365',
            'settings.email_organization_daily'=>'sometimes|boolean','settings.email_driver_daily'=>'sometimes|boolean','settings.email_driver_weekly'=>'sometimes|boolean','settings.email_score_changes'=>'sometimes|boolean',
            'settings.telemetry_interval_seconds'=>'sometimes|integer|min:2|max:30','settings.harsh_braking_ms2'=>'sometimes|numeric|min:0.5|max:20','settings.rapid_acceleration_ms2'=>'sometimes|numeric|min:0.5|max:20',
            'settings.maf_air_fuel_ratio'=>'sometimes|numeric|min:5|max:30','settings.maf_fuel_density_g_per_litre'=>'sometimes|numeric|min:500|max:1000',
            'settings.co2_petrol_kg_per_litre'=>'sometimes|numeric|min:0.1|max:10','settings.co2_diesel_kg_per_litre'=>'sometimes|numeric|min:0.1|max:10',
            'settings.device_offline_after_s'=>'sometimes|integer|min:90|max:3600','settings.unidentified_driving_alert_after_s'=>'sometimes|integer|min:30|max:3600',
            'settings.notification_locales'=>'sometimes|array|min:1|max:3','settings.notification_locales.*'=>'in:en,ms,zh']);
        DB::table('organizations')->where('id',$organization)->lockForUpdate()->first();
        $e=app(\App\Services\Entitlements::class);
        if (isset($data['settings']['telemetry_interval_seconds'])) {
            $e->requireActive($organization);
            abort_if($data['settings']['telemetry_interval_seconds'] < ($e->plan($organization)['telemetry_min_seconds'] ?? 2),422,'Sampling interval is faster than the plan permits.');
        }
        if (isset($data['settings']['harsh_braking_ms2']) || isset($data['settings']['rapid_acceleration_ms2'])) $e->feature($organization,'safety');
        $old = DB::table('organization_settings')->where('organization_id',$organization)->lockForUpdate()->first();
        abort_if((int)($old->version ?? 1) !== (int)$data['version'],409);
        $values = ['settings'=>json_encode(array_replace($old ? json_decode($old->settings,true) : [],$data['settings']),JSON_THROW_ON_ERROR),'version'=>$data['version']+1,'updated_by'=>$request->user()->id,'updated_at'=>now()];
        if ($old) DB::table('organization_settings')->where('organization_id',$organization)->update($values);
        else DB::table('organization_settings')->insert(['organization_id'=>$organization,'created_at'=>now()] + $values);
        return ApiResponse::success($request,['version'=>$data['version']+1,'device_delivery'=>'Devices auto-sync organization settings on boot and on their command poll loop; you can also use POST vehicles/{vehicle}/configuration to enqueue signed settings immediately.'],'Settings Updated');
    }
    public function members(Request $request, string $organization)
    {
        $this->authorize($request,'members.read');
        $filter=$request->validate(['per_page'=>'nullable|integer|min:1|max:200','cursor'=>'nullable|string|max:512']);
        $page=Membership::with('user:id,display_name,email_normalized')->where('organization_id',$organization)->orderBy('id')->cursorPaginate($filter['per_page'] ?? 50);
        $items=collect($page->items())->map(fn ($m)=>['id'=>$m->id,'user_id'=>$m->user_id,'display_name'=>$m->user?->display_name,'email_normalized'=>$m->user?->email_normalized,'role'=>$m->role,'status'=>$m->status,'version'=>$m->version,'permissions'=>app(Permissions::class)->keys($m)]);
        return ApiResponse::success($request,['items'=>$items,'pagination'=>['per_page'=>$page->perPage(),'next_cursor'=>$page->nextCursor()?->encode()],'links'=>['next'=>$page->nextPageUrl()]],'Members Retrieved');
    }
    public function updateMember(Request $request, string $organization, string $membership)
    {
        $this->authorize($request,'members.manage');
        $data = $request->validate(['version'=>'required|integer|min:1','status'=>'sometimes|in:active,suspended,removed','roles'=>'sometimes|array|min:1|max:8','roles.*'=>['string','distinct',Rule::exists('roles','code')]]);
        DB::table('organizations')->where('id',$organization)->lockForUpdate()->first();
        $target = Membership::where('organization_id',$organization)->whereKey($membership)->lockForUpdate()->first() ?? abort(404);
        abort_if((int)$target->version !== (int)$data['version'],409);
        $owner = $this->isOwner($target);
        // Ownership changes have one dedicated, recently authenticated transition.
        if ($owner) {
            $this->authorize($request,'ownership.transfer'); $this->recent($request);
            abort_if(isset($data['roles']),409,'Use an ownership transfer to change owner roles.');
            if (($data['status'] ?? 'active') !== 'active') abort_if($this->ownerCount($organization) <= 1,409,'Cannot remove the last active owner.');
        } else abort_if(in_array('owner',$data['roles'] ?? [],true),409,'Use an ownership transfer.');
        if (isset($data['roles'])) {
            $target->role = $data['roles'][0];
            DB::table('membership_roles')->where('organization_id',$organization)->where('membership_id',$target->id)->delete();
            foreach ($data['roles'] as $role) DB::table('membership_roles')->insert(['organization_id'=>$organization,'membership_id'=>$target->id,'role_code'=>$role]);
        }
        $oldStatus=$target->status;
        $target->status = $data['status'] ?? $target->status; $target->version++; $target->save();
        if ($target->status !== $oldStatus) app(\App\Services\Mail\NotificationEmails::class)->status('membership',(object)$target->getAttributes(),$target->user_id,$organization);
        return ApiResponse::success($request,['version'=>$target->version],'Member Updated');
    }
    public function transferOwnership(Request $request, string $organization)
    {
        $this->authorize($request,'ownership.transfer'); $this->recent($request);
        $data = $request->validate(['membership_id'=>'required|uuid','version'=>'required|integer|min:1']);
        DB::table('organizations')->where('id',$organization)->lockForUpdate()->first();
        $current = Membership::where('organization_id',$organization)->whereKey($request->attributes->get('membership')->id)->lockForUpdate()->first();
        abort_unless($this->isOwner($current),403);
        $target = Membership::where('organization_id',$organization)->whereKey($data['membership_id'])->where('status','active')->whereHas('user',fn ($q)=>$q->where('status','active'))->lockForUpdate()->first() ?? abort(404);
        abort_if($target->id === $current->id || (int)$target->version !== (int)$data['version'],409);
        DB::table('membership_roles')->where('organization_id',$organization)->whereIn('membership_id',[$current->id,$target->id])->delete();
        $target->role='owner'; $target->version++; $target->save();
        $current->role='admin'; $current->version++; $current->save();
        return ApiResponse::success($request,['owner_membership_id'=>$target->id],'Ownership Transferred');
    }
    private function isOwner(Membership $membership): bool
    {
        return $membership->role === 'owner' || DB::table('membership_roles')->where('organization_id',$membership->organization_id)->where('membership_id',$membership->id)->where('role_code','owner')->exists();
    }
    private function ownerCount(string $organization): int
    {
        return Membership::where('organization_id',$organization)->where('status','active')->whereHas('user',fn ($q)=>$q->where('status','active'))->get()->filter(fn ($m)=>$this->isOwner($m))->count();
    }
}
