<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Calendar compliance registry; reminders commit in-app/email intents, evidence is manually attested. */
class ComplianceController
{
    private function org(Request $request): string { return (string) $request->attributes->get('membership')->organization_id; }
    private function authorize(Request $request, bool $write = false): void
    {
        $membership = $request->attributes->get('membership');
        $permission = str_ends_with($request->route()?->getActionName() ?? '', '@verify') ? 'compliance.verify' : ($write ? 'compliance.write' : 'compliance.read');
        abort_unless(app(\App\Services\Permissions::class)->allows($membership, $permission), 403);
    }
    private function row(string $table, string $org, string $id, bool $lock = false): object
    {
        $query = DB::table($table)->where('organization_id',$org)->where('id',$id);
        return ($lock ? $query->lockForUpdate() : $query)->first() ?? abort(404);
    }
    private function event(string $org, string $obligation, ?string $record, string $actor, string $event, array $details = []): void
    {
        DB::table('compliance_action_events')->insert(['id'=>(string) Str::uuid(),'organization_id'=>$org,'obligation_id'=>$obligation,'record_id'=>$record,'actor_id'=>$actor,'event'=>$event,'details'=>json_encode($details, JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);
    }
    public function types(Request $request, string $organization)
    {
        $this->authorize($request);
        $org = $this->org($request);
        $items = DB::table('compliance_types')->where('organization_id',$org)->orderBy('name')->limit(100)->get();
        if ($items->isEmpty()) {
            app(\App\Services\ComplianceDefaults::class)->ensureTypes($org);
            $items = DB::table('compliance_types')->where('organization_id',$org)->orderBy('name')->limit(100)->get();
        }
        return ApiResponse::success($request,['items'=>$items],'Compliance Types Retrieved');
    }
    public function createType(Request $request, string $organization)
    {
        $this->authorize($request,true); $data=$request->validate(['code'=>'required|string|regex:/^[A-Za-z0-9_-]{1,80}$/','name'=>'required|string|max:160','subject_type'=>'required|in:vehicle,driver','warning_days'=>'nullable|integer|min:0|max:3650']);
        $org=$this->org($request); $id=(string) Str::uuid(); $data['code']=strtolower($data['code']);
        DB::transaction(function () use ($org,$data,$id) {
            DB::table('organizations')->where('id',$org)->lockForUpdate()->first();
            abort_if(DB::table('compliance_types')->where('organization_id',$org)->where('code',$data['code'])->exists(),409,'Type code already exists.');
            DB::table('compliance_types')->insert(['id'=>$id,'organization_id'=>$org,'code'=>$data['code'],'name'=>$data['name'],'subject_type'=>$data['subject_type'],'warning_days'=>$data['warning_days'] ?? 30,'active'=>true,'version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        });
        return ApiResponse::success($request,['id'=>$id],'Compliance Type Created','',201);
    }
    public function obligations(Request $request, string $organization)
    {
        $this->authorize($request);
        $items=DB::table('compliance_obligations as o')
            ->leftJoin('compliance_types as t','t.id','=','o.compliance_type_id')
            ->leftJoin('vehicles as v','v.id','=','o.vehicle_id')
            ->leftJoin('drivers as dr','dr.id','=','o.driver_id')
            ->where('o.organization_id',$this->org($request))
            ->orderBy('o.created_at')
            ->limit(100)
            ->get(['o.*','t.code as compliance_type_code','t.name as compliance_type_name','t.warning_days','v.registration_number','dr.name as driver_name'])
            ->map(function ($item) {
                $item->warning_days=$item->warning_days !== null ? (int)$item->warning_days : null;
                $item->subject_name=$item->registration_number ?: $item->driver_name;
                unset($item->driver_name);
                return $item;
            });
        return ApiResponse::success($request,['items'=>$items],'Compliance Obligations Retrieved');
    }
    public function updateType(Request $request,string $organization,string $type)
    {
        $this->authorize($request,true);
        abort_if(array_diff(array_keys($request->all()),['version','name','warning_days','active']),422,'Only reminder configuration may be updated.');
        $data=$request->validate(['version'=>'required|integer|min:1','name'=>'sometimes|required|string|max:160','warning_days'=>'sometimes|required|integer|min:0|max:3650','active'=>'sometimes|required|boolean']);
        return DB::transaction(function() use($request,$organization,$type,$data) {
            DB::table('organizations')->where('id',$organization)->lockForUpdate()->first(); $row=$this->row('compliance_types',$organization,$type,true);
            abort_unless((int)$row->version===(int)$data['version'],409);
            DB::table('compliance_types')->where('organization_id',$organization)->where('id',$type)->update(array_diff_key($data,['version'=>true])+['version'=>$row->version+1,'updated_at'=>now()]);
            // Historical occurrences stay append-only; the evaluator creates the new rule version.
            $alerts=DB::table('alert_occurrences as a')->join('compliance_obligations as o',fn($j)=>$j->on('o.organization_id','=','a.organization_id')->whereRaw("a.rule_key = 'compliance:' || o.id"))
                ->where('o.organization_id',$organization)->where('o.compliance_type_id',$type)->where('a.kind','compliance.threshold_crossed')->where('a.state','open')->get(['a.id']);
            foreach ($alerts as $alert) {
                DB::table('alert_occurrences')->where('id',$alert->id)->update(['state'=>'superseded','updated_at'=>now()]);
                DB::table('compliance_alert_transitions')->insert(['id'=>(string)Str::uuid(),'organization_id'=>$organization,'alert_occurrence_id'=>$alert->id,'from_state'=>'open','to_state'=>'superseded','reason'=>'reminder_configuration_changed','occurred_at'=>now()]);
            }
            return ApiResponse::success($request,['id'=>$type,'version'=>$row->version+1],'Compliance Reminder Settings Updated');
        });
    }
    public function createObligation(Request $request, string $organization)
    {
        $this->authorize($request,true); $data=$request->validate(['compliance_type_id'=>'required|uuid','subject_type'=>'required|in:vehicle,driver','subject_id'=>'required|uuid']);
        $org=$this->org($request); $id=(string) Str::uuid();
        DB::transaction(function () use ($request,$data,$org,$id) {
            DB::table('organizations')->where('id',$org)->lockForUpdate()->first();
            $type=$this->row('compliance_types',$org,$data['compliance_type_id'],true);
            abort_unless($type->active && $type->subject_type === $data['subject_type'],422,'Type and subject must match.');
            $column=$data['subject_type']==='vehicle'?'vehicle_id':'driver_id';
            $subject=$this->row($data['subject_type']==='vehicle'?'vehicles':'drivers',$org,$data['subject_id'],true);
            abort_if($subject->status==='archived',409,'Subject is archived.');
            abort_if(DB::table('compliance_obligations')->where('organization_id',$org)->where('compliance_type_id',$type->id)->where($column,$data['subject_id'])->exists(),409,'Obligation already exists.');
            DB::table('compliance_obligations')->insert(['id'=>$id,'organization_id'=>$org,'compliance_type_id'=>$type->id,'subject_type'=>$data['subject_type'],$column=>$data['subject_id'],'applicability'=>'applicable','version'=>1,'created_at'=>now(),'updated_at'=>now()]);
            $this->event($org,$id,null,$request->user()->id,'obligation_created');
        });
        return ApiResponse::success($request,['id'=>$id,'status'=>'unknown'],'Compliance Obligation Created','Evidence must be verified before status is valid.',201);
    }
    public function showObligation(Request $request, string $organization, string $obligation)
    {
        $this->authorize($request); $org=$this->org($request); $item=$this->row('compliance_obligations',$org,$obligation);
        return ApiResponse::success($request,['obligation'=>$item,'status'=>$this->evaluate($org,$item)],'Compliance Obligation Retrieved');
    }
    public function status(Request $request, string $organization)
    {
        $this->authorize($request); $org=$this->org($request);
        $items=DB::table('compliance_obligations as o')
            ->leftJoin('compliance_types as t','t.id','=','o.compliance_type_id')
            ->leftJoin('vehicles as v','v.id','=','o.vehicle_id')
            ->leftJoin('drivers as dr','dr.id','=','o.driver_id')
            ->where('o.organization_id',$org)
            ->orderBy('o.created_at')
            ->limit(100)
            ->get(['o.*','t.code as compliance_type_code','t.name as compliance_type_name','t.warning_days','v.registration_number','v.make','v.model','dr.name as driver_name'])
            ->map(fn ($item)=>[
                'obligation_id'=>$item->id,
                'subject_type'=>$item->subject_type,
                'vehicle_id'=>$item->vehicle_id,
                'driver_id'=>$item->driver_id,
                'compliance_type_code'=>$item->compliance_type_code,
                'compliance_type_name'=>$item->compliance_type_name,
                'warning_days'=>$item->warning_days !== null ? (int)$item->warning_days : null,
                'registration_number'=>$item->registration_number,
                'make'=>$item->make,
                'model'=>$item->model,
                'subject_name'=>$item->registration_number ?: $item->driver_name,
            ]+$this->evaluate($org,$item));
        return ApiResponse::success($request,['items'=>$items],'Compliance Status Retrieved','Vehicle reminders queue in-app and HTML email notifications. Driver reminders and automated evidence verification are not implemented.');
    }
    public function records(Request $request, string $organization)
    {
        $this->authorize($request); $org=$this->org($request);
        $v=$request->validate(['state'=>'nullable|in:pending,verified','vehicle_id'=>'nullable|uuid','per_page'=>'nullable|integer|min:1|max:100','cursor'=>'nullable|string|max:512']);
        $p=DB::table('compliance_records as r')
            ->join('compliance_obligations as o',fn($j)=>$j->on('o.id','=','r.obligation_id')->on('o.organization_id','=','r.organization_id'))
            ->leftJoin('compliance_types as t','t.id','=','o.compliance_type_id')
            ->leftJoin('vehicles as v','v.id','=','o.vehicle_id')
            ->leftJoin('drivers as dr','dr.id','=','o.driver_id')
            ->where('r.organization_id',$org)
            ->when($v['state'] ?? null,fn($q,$state)=>$q->where('r.state',$state))
            ->when($v['vehicle_id'] ?? null,fn($q,$vid)=>$q->where('o.vehicle_id',$vid))
            ->orderByDesc('r.created_at')
            ->orderBy('r.id')
            ->cursorPaginate($v['per_page'] ?? 50,['r.id','r.obligation_id','r.cycle_number','r.reference_number','r.issuing_authority','r.issued_on','r.valid_from','r.expires_on','r.evidence_reference','r.state','r.verified_at','r.suspended_at','r.created_at','t.code as compliance_type_code','t.name as compliance_type_name','o.subject_type','o.vehicle_id','o.driver_id','v.registration_number','dr.name as driver_name']);
        $items=collect($p->items())->map(function ($item) {
            $item->subject_name=$item->registration_number ?: $item->driver_name;
            unset($item->driver_name);
            return $item;
        });
        return ApiResponse::success($request,['items'=>$items,'pagination'=>['next_cursor'=>$p->nextCursor()?->encode(),'per_page'=>$p->perPage()]],'Compliance Records Retrieved');
    }
    public function evaluate(string $org, object $obligation): array
    {
        if ($obligation->applicability !== 'applicable') return ['status'=>'not_applicable','record_id'=>null,'expires_on'=>null,'days_remaining'=>null];
        $type=$this->row('compliance_types',$org,$obligation->compliance_type_id);
        $timezone=DB::table('organizations')->where('id',$org)->value('timezone') ?: 'Asia/Kuala_Lumpur';
        $today=CarbonImmutable::now($timezone)->startOfDay(); $date=$today->toDateString();
        // Only verified records in force at the local date participate; future rows never replace current coverage.
        $records=DB::table('compliance_records')->where('organization_id',$org)->where('obligation_id',$obligation->id)->where('state','verified')->where('valid_from','<=',$date)->orderByDesc('expires_on')->orderByDesc('valid_from')->get();
        $available=$records->first(function ($item) use ($date) {
            return $item->expires_on >= $date && (!$item->suspended_at || CarbonImmutable::parse($item->suspended_at)->isFuture());
        });
        $record=$available ?? $records->first();
        if (!$record) return ['status'=>'unknown','record_id'=>null,'expires_on'=>null,'days_remaining'=>null];
        $remaining=(int) CarbonImmutable::parse($date,'UTC')->diffInDays(CarbonImmutable::parse($record->expires_on,'UTC')->startOfDay(),false);
        $state=$remaining < 0 ? 'expired' : ($record->suspended_at && CarbonImmutable::parse($record->suspended_at)->lessThanOrEqualTo(now()) ? 'suspended' : ($remaining <= (int)$type->warning_days ? 'due_soon' : 'valid'));
        return ['status'=>$state,'record_id'=>$record->id,'expires_on'=>$record->expires_on,'days_remaining'=>$remaining];
    }
    public function renew(Request $request, string $organization, string $obligation)
    {
        $this->authorize($request,true); $data=$request->validate(['reference_number'=>'nullable|string|max:160','issuing_authority'=>'nullable|string|max:160','issued_on'=>'nullable|date_format:Y-m-d|before_or_equal:expires_on','valid_from'=>'required|date_format:Y-m-d','expires_on'=>'required|date_format:Y-m-d|after_or_equal:valid_from','evidence_reference'=>'nullable|string|max:255']);
        $key=$request->header('Idempotency-Key'); if (!is_string($key) || strlen($key)<8 || strlen($key)>255) throw ValidationException::withMessages(['Idempotency-Key'=>['Provide an idempotency key of 8 to 255 characters.']]);
        ksort($data); $keyHash=hash('sha256',$key); $payloadHash=hash('sha256',json_encode($data,JSON_THROW_ON_ERROR)); $org=$this->org($request);
        $result=DB::transaction(function () use ($request,$data,$org,$obligation,$keyHash,$payloadHash) {
            $this->row('compliance_obligations',$org,$obligation,true);
            $old=DB::table('compliance_records')->where('organization_id',$org)->where('obligation_id',$obligation)->where('idempotency_hash',$keyHash)->first();
            if ($old) { abort_unless(hash_equals($old->payload_hash,$payloadHash),409,'Idempotency key was used for different details.'); return ['id'=>$old->id,'cycle_number'=>$old->cycle_number]; }
            $id=(string) Str::uuid(); $cycle=(int) DB::table('compliance_records')->where('organization_id',$org)->where('obligation_id',$obligation)->max('cycle_number')+1;
            DB::table('compliance_records')->insert(array_merge($data,['id'=>$id,'organization_id'=>$org,'obligation_id'=>$obligation,'cycle_number'=>$cycle,'state'=>'pending','idempotency_hash'=>$keyHash,'payload_hash'=>$payloadHash,'created_at'=>now(),'updated_at'=>now()]));
            $this->event($org,$obligation,$id,$request->user()->id,'renewal_recorded'); return ['id'=>$id,'cycle_number'=>$cycle];
        });
        return ApiResponse::success($request,$result,'Compliance Renewal Recorded','The record must be verified before it affects status.',201);
    }
    public function verify(Request $request, string $organization, string $record)
    {
        $this->authorize($request,true); $org=$this->org($request);
        $result=DB::transaction(function () use ($request,$org,$record) {
            $probe=$this->row('compliance_records',$org,$record); $this->row('compliance_obligations',$org,$probe->obligation_id,true); $item=$this->row('compliance_records',$org,$record,true);
            if ($item->state === 'verified') return ['id'=>$record,'state'=>'verified'];
            abort_unless($item->state === 'pending',409,'Record cannot be verified.');
            if (!$item->evidence_reference || !trim($item->evidence_reference)) throw ValidationException::withMessages(['evidence_reference'=>['Evidence reference is required before verification.']]);
            DB::table('compliance_records')->where('id',$record)->update(['state'=>'verified','verified_by'=>$request->user()->id,'verified_at'=>now(),'updated_at'=>now()]);
            $this->event($org,$item->obligation_id,$record,$request->user()->id,'record_verified'); return ['id'=>$record,'state'=>'verified'];
        });
        return ApiResponse::success($request,$result,'Compliance Record Verified','Future coverage begins on its valid-from date.');
    }
    public function suspend(Request $request, string $organization, string $record)
    {
        $this->authorize($request,true); $data=$request->validate(['reason'=>'required|string|max:500']); $org=$this->org($request);
        DB::transaction(function () use ($request,$org,$record,$data) {
            $probe=$this->row('compliance_records',$org,$record); $this->row('compliance_obligations',$org,$probe->obligation_id,true); $item=$this->row('compliance_records',$org,$record,true);
            abort_unless($item->state==='verified' && !$item->suspended_at,409,'Record is not active or is already suspended.');
            DB::table('compliance_records')->where('id',$record)->update(['suspended_at'=>now(),'suspension_reason'=>$data['reason'],'updated_at'=>now()]);
            $this->event($org,$item->obligation_id,$record,$request->user()->id,'record_suspended',['reason'=>$data['reason']]);
        });
        return ApiResponse::success($request,['id'=>$record],'Compliance Record Suspended');
    }
    public function history(Request $request, string $organization, string $record)
    {
        $this->authorize($request); $org=$this->org($request); $item=$this->row('compliance_records',$org,$record);
        $records=DB::table('compliance_records')->where('organization_id',$org)->where('obligation_id',$item->obligation_id)->orderBy('cycle_number')->get(['id','cycle_number','reference_number','issuing_authority','issued_on','valid_from','expires_on','state','verified_at','suspended_at','suspension_reason']);
        $events=DB::table('compliance_action_events')->where('organization_id',$org)->where('obligation_id',$item->obligation_id)->orderBy('created_at')->get(['id','record_id','actor_id','event','created_at']);
        return ApiResponse::success($request,['records'=>$records,'events'=>$events],'Compliance History Retrieved');
    }
}
