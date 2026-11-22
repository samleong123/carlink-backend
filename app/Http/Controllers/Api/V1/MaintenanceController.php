<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Maintenance registry, service cycles and coverage-aware distance evaluation. */
class MaintenanceController
{
    private function org(Request $r): string { return (string) $r->attributes->get('membership')->organization_id; }
    private function authorize(Request $r, bool $write = false): void
    {
        $m = $r->attributes->get('membership');
        $permission = $write ? 'maintenance.write' : 'maintenance.read';
        if (str_ends_with($r->route()?->getActionName() ?? '', '@completeOrder')) $permission = 'maintenance.complete';
        abort_unless(app(\App\Services\Permissions::class)->allows($m, $permission), 403);
    }
    private function row(string $table, string $org, string $id, bool $lock = false): object
    {
        $q = DB::table($table)->where('organization_id', $org)->where('id', $id);
        return ($lock ? $q->lockForUpdate() : $q)->first() ?? abort(404);
    }
    private function id(): string { return (string) Str::uuid(); }
    private function snapshot(string $org, string $planId): object
    {
        $plan = $this->row('maintenance_plans', $org, $planId);
        abort_if($plan->status !== 'active', 409, 'Plan is inactive.');
        return DB::table('maintenance_plan_revisions')->where('organization_id', $org)->where('plan_id', $planId)->where('revision', $plan->current_revision)->first() ?? abort(409);
    }
    private function validateRevision(Request $r): array
    {
        $input = $r->validate([
            'interval' => 'required|array', 'interval.distance_km' => 'nullable|numeric|gt:0|max:100000000',
            'interval.calendar_months' => 'nullable|integer|min:1|max:1200', 'interval.calendar_days' => 'nullable|integer|min:1|max:36500',
            'interval.due_when' => 'required|in:any', 'thresholds' => 'sometimes|array|min:1|max:32',
            'thresholds.*.key' => 'required|string|regex:/^[A-Za-z0-9_-]{1,64}$/', 'thresholds.*.severity' => 'required|in:info,warning,high,critical',
            'thresholds.*.remaining_km_lte' => 'nullable|numeric', 'thresholds.*.remaining_days_lte' => 'nullable|integer',
            'thresholds.*.match' => 'required|in:any,all', 'thresholds.*.channels' => 'required|array|min:1', 'thresholds.*.channels.*' => 'in:in_app,email',
        ]);
        $i = $input['interval'];
        if (!isset($input['thresholds'])) {
            $input['thresholds']=[];
            if (!empty($i['calendar_months']) || !empty($i['calendar_days'])) $input['thresholds'][]=['key'=>'renewal_30_days','severity'=>'warning','remaining_days_lte'=>30,'match'=>'any','channels'=>['in_app','email']];
            $due=['key'=>'due','severity'=>'critical','match'=>'any','channels'=>['in_app','email']];
            if (!empty($i['calendar_months']) || !empty($i['calendar_days'])) $due['remaining_days_lte']=0;
            if (!empty($i['distance_km'])) $due['remaining_km_lte']=0;
            $input['thresholds'][]=$due;
        }
        if (empty($i['distance_km']) && empty($i['calendar_months']) && empty($i['calendar_days'])) throw ValidationException::withMessages(['interval'=>['At least one interval is required.']]);
        if (!empty($i['calendar_months']) && !empty($i['calendar_days'])) throw ValidationException::withMessages(['interval'=>['Use months or days for one calendar interval.']]);
        $keys = []; $lastSeverity = -1; $lastDistance = null; $lastDays = null; $severityLevels = ['info'=>0,'warning'=>1,'high'=>2,'critical'=>3];
        foreach ($input['thresholds'] as $t) {
            if (isset($keys[$t['key']])) throw ValidationException::withMessages(['thresholds'=>['Threshold keys must be unique.']]);
            $keys[$t['key']] = true;
            $distance = array_key_exists('remaining_km_lte', $t) && $t['remaining_km_lte'] !== null;
            $calendar = array_key_exists('remaining_days_lte', $t) && $t['remaining_days_lte'] !== null;
            if ((!$distance && !$calendar) || ($distance && empty($i['distance_km'])) || ($calendar && empty($i['calendar_months']) && empty($i['calendar_days']))) throw ValidationException::withMessages(['thresholds'=>['Each threshold must refer to an enabled metric.']]);
            $level = $severityLevels[$t['severity']];
            if ($level < $lastSeverity || ($distance && $lastDistance !== null && (float)$t['remaining_km_lte'] > $lastDistance) || ($calendar && $lastDays !== null && (int)$t['remaining_days_lte'] > $lastDays)) throw ValidationException::withMessages(['thresholds'=>['Thresholds must progress toward the due point without reducing severity.']]);
            $lastSeverity=$level; if ($distance) $lastDistance=(float)$t['remaining_km_lte']; if ($calendar) $lastDays=(int)$t['remaining_days_lte'];
        }
        return $input;
    }
    public function plans(Request $r, string $organization)
    {
        $this->authorize($r); return ApiResponse::success($r, ['items'=>DB::table('maintenance_plans')->where('organization_id',$this->org($r))->orderBy('name')->limit(100)->get()], 'Maintenance Plans Retrieved');
    }
    public function createPlan(Request $r, string $organization)
    {
        $this->authorize($r,true); $metadata = $r->validate(['name'=>'required|string|max:160','service_type'=>'required|string|max:80']); $revision=$this->validateRevision($r);
        $org=$this->org($r); $id=$this->id();
        DB::transaction(function () use ($id,$org,$metadata,$revision,$r) {
            DB::table('maintenance_plans')->insert(['id'=>$id,'organization_id'=>$org,'name'=>$metadata['name'],'service_type'=>$metadata['service_type'],'status'=>'active','current_revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('maintenance_plan_revisions')->insert(['id'=>$this->id(),'organization_id'=>$org,'plan_id'=>$id,'revision'=>1,'interval'=>json_encode($revision['interval'],JSON_THROW_ON_ERROR),'thresholds'=>json_encode($revision['thresholds'],JSON_THROW_ON_ERROR),'created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);
        });
        return ApiResponse::success($r, ['id'=>$id], 'Maintenance Plan Created', '', 201);
    }
    public function showPlan(Request $r, string $organization, string $plan)
    {
        $this->authorize($r); $org=$this->org($r); $item=$this->row('maintenance_plans',$org,$plan);
        $revisions=DB::table('maintenance_plan_revisions')->where('organization_id',$org)->where('plan_id',$plan)->orderBy('revision')->get()->map(fn ($v)=>$this->decodeRevision($v));
        return ApiResponse::success($r,['plan'=>$item,'revisions'=>$revisions],'Maintenance Plan Retrieved');
    }
    private function decodeRevision(object $r): object { $r->interval=json_decode($r->interval,true); $r->thresholds=json_decode($r->thresholds,true); return $r; }
    public function revisePlan(Request $r, string $organization, string $plan)
    {
        $this->authorize($r,true); $data=$this->validateRevision($r); $org=$this->org($r);
        $version=DB::transaction(function () use ($r,$plan,$org,$data) {
            $p=$this->row('maintenance_plans',$org,$plan,true); abort_if($p->status!=='active',409); $version=$p->current_revision+1;
            DB::table('maintenance_plan_revisions')->insert(['id'=>$this->id(),'organization_id'=>$org,'plan_id'=>$plan,'revision'=>$version,'interval'=>json_encode($data['interval'],JSON_THROW_ON_ERROR),'thresholds'=>json_encode($data['thresholds'],JSON_THROW_ON_ERROR),'created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('maintenance_plans')->where('id',$plan)->update(['current_revision'=>$version,'updated_at'=>now()]); return $version;
        });
        return ApiResponse::success($r,['revision'=>$version,'effective_policy'=>'next_cycle'],'Maintenance Revision Created','Current cycles retain their snapshots.',201);
    }
    public function assign(Request $r, string $organization, string $vehicle)
    {
        $this->authorize($r,true); $data=$r->validate(['plan_id'=>'required|uuid','last_serviced_at'=>'nullable|date|before_or_equal:now','carry_in_distance_m'=>'nullable|integer|min:0|max:10000000000','carry_in_as_of'=>'required_with:carry_in_distance_m|nullable|date|before_or_equal:now|after_or_equal:last_serviced_at','carry_in_reason'=>'required_with:carry_in_distance_m|nullable|string|min:3|max:500']); $org=$this->org($r); $assignment=$this->id();
        if (isset($data['carry_in_distance_m']) && empty($data['last_serviced_at'])) throw ValidationException::withMessages(['last_serviced_at'=>['Service time is required for a carry-in baseline.']]);
        DB::transaction(function () use ($org,$vehicle,$data,$assignment) {
            DB::table('organizations')->where('id',$org)->lockForUpdate()->first(); $v=$this->row('vehicles',$org,$vehicle,true); abort_if($v->status==='archived',409);
            $revision=$this->snapshot($org,$data['plan_id']);
            abort_if(DB::table('vehicle_maintenance_plans')->where('organization_id',$org)->where('vehicle_id',$vehicle)->where('plan_id',$data['plan_id'])->exists(),409,'Plan already assigned.');
            DB::table('vehicle_maintenance_plans')->insert(['id'=>$assignment,'organization_id'=>$org,'vehicle_id'=>$vehicle,'plan_id'=>$data['plan_id'],'status'=>'active','version'=>1,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('maintenance_service_cycles')->insert(['id'=>$this->id(),'organization_id'=>$org,'assignment_id'=>$assignment,'ordinal'=>1,'serviced_at'=>$data['last_serviced_at'] ?? null,'interval_snapshot'=>$revision->interval,'thresholds_snapshot'=>$revision->thresholds,'baseline_quality'=>isset($data['carry_in_distance_m']) ? 'attested_carry_in' : 'unknown_baseline','carry_in_distance_m'=>$data['carry_in_distance_m'] ?? null,'carry_in_as_of'=>$data['carry_in_as_of'] ?? null,'carry_in_reason'=>$data['carry_in_reason'] ?? null,'created_at'=>now(),'updated_at'=>now()]);
            app(\App\Services\Maintenance\EvaluateMaintenance::class)->vehicle($org,$vehicle);
        });
        return ApiResponse::success($r,['id'=>$assignment,'baseline_quality'=>isset($data['carry_in_distance_m']) ? 'attested_carry_in' : 'unknown_baseline'],'Maintenance Plan Assigned','Missing pre-monitoring service usage remains unknown.',201);
    }
    public function vehiclePlans(Request $r, string $organization, string $vehicle)
    {
        $this->authorize($r); $org=$this->org($r); $this->row('vehicles',$org,$vehicle);
        $timezone=DB::table('organizations')->where('id',$org)->value('timezone') ?: 'Asia/Kuala_Lumpur';
        $rows=DB::table('vehicle_maintenance_plans as a')->join('maintenance_plans as p','p.id','=','a.plan_id')->join('maintenance_service_cycles as c','c.assignment_id','=','a.id')
            ->where('a.organization_id',$org)->where('a.vehicle_id',$vehicle)->whereNull('c.ended_at')->select('a.id','a.version','a.status','p.name','p.service_type','c.id as cycle_id','c.serviced_at','c.interval_snapshot','c.baseline_quality','c.carry_in_distance_m','c.carry_in_as_of')->get()->map(function ($v) use ($timezone,$org,$vehicle) {
                $evaluation=app(\App\Services\Maintenance\MaintenanceStatus::class)->cycle($org,$vehicle,$v,CarbonImmutable::now('UTC'));
                $interval=json_decode($v->interval_snapshot,true); unset($v->interval_snapshot); $v->interval=$interval; $v->remaining_km=null; $v->distance_status='unavailable'; $v->due_date=null; $v->remaining_days=null;
                if ($v->serviced_at && (!empty($interval['calendar_months']) || !empty($interval['calendar_days']))) {
                    $date=CarbonImmutable::parse($v->serviced_at)->setTimezone($timezone)->startOfDay();
                    $due=!empty($interval['calendar_months']) ? $date->addMonthsNoOverflow((int)$interval['calendar_months']) : $date->addDays((int)$interval['calendar_days']);
                    $v->due_date=$due->toDateString(); $today=CarbonImmutable::now($timezone)->startOfDay(); $v->remaining_days=(int)$today->diffInDays($due,false);
                }
                foreach ($evaluation as $key=>$value) $v->{$key}=$value;
                return $v;
            });
        return ApiResponse::success($r,['items'=>$rows],'Vehicle Maintenance Retrieved','Distance is an estimated lower bound; missing usage may make service due sooner.');
    }
    private function event(string $org, string $order, string $actor, string $event): void
    {
        DB::table('maintenance_action_events')->insert(['id'=>$this->id(),'organization_id'=>$org,'work_order_id'=>$order,'actor_id'=>$actor,'event'=>$event,'created_at'=>now(),'updated_at'=>now()]);
    }
    public function orders(Request $r, string $organization)
    {
        $this->authorize($r); return ApiResponse::success($r,['items'=>DB::table('maintenance_work_orders')->where('organization_id',$this->org($r))->orderByDesc('created_at')->limit(100)->get(['id','vehicle_id','number','status','workshop_name','scheduled_at','version','created_at','updated_at'])], 'Work Orders Retrieved');
    }
    public function showOrder(Request $r, string $organization, string $order)
    {
        $this->authorize($r); $org=$this->org($r); $item=$this->row('maintenance_work_orders',$org,$order); unset($item->completion_idempotency_hash, $item->completion_payload_hash);
        $items=DB::table('maintenance_work_order_items')->where('organization_id',$org)->where('work_order_id',$order)->get();
        return ApiResponse::success($r,['order'=>$item,'items'=>$items],'Work Order Retrieved');
    }
    public function createOrder(Request $r, string $organization)
    {
        $this->authorize($r,true); $data=$r->validate(['vehicle_id'=>'required|uuid','number'=>'required|string|max:80','assignment_ids'=>'required|array|min:1|max:30','assignment_ids.*'=>'required|uuid|distinct','scheduled_at'=>'nullable|date','workshop_name'=>'nullable|string|max:160']);
        $org=$this->org($r); $id=$this->id();
        DB::transaction(function () use ($data,$org,$id,$r) {
            DB::table('organizations')->where('id',$org)->lockForUpdate()->first(); $vehicle=$this->row('vehicles',$org,$data['vehicle_id'],true); abort_if($vehicle->status==='archived',409);
            abort_if(DB::table('maintenance_work_orders')->where('organization_id',$org)->where('number',$data['number'])->exists(),409,'Order number already exists.');
            $cycles=[];
            foreach ($data['assignment_ids'] as $assignment) {
                $a=$this->row('vehicle_maintenance_plans',$org,$assignment,true); abort_unless($a->vehicle_id===$data['vehicle_id'] && $a->status==='active',404);
                $cycle=DB::table('maintenance_service_cycles')->where('organization_id',$org)->where('assignment_id',$assignment)->whereNull('ended_at')->lockForUpdate()->first() ?? abort(409,'No active cycle.');
                $exists=DB::table('maintenance_work_order_items as i')->join('maintenance_work_orders as o','o.id','=','i.work_order_id')->where('i.organization_id',$org)->where('i.cycle_id',$cycle->id)->whereIn('o.status',['draft','scheduled','in_progress'])->exists();
                abort_if($exists,409,'An open order already covers this service cycle.'); $cycles[$assignment]=$cycle->id;
            }
            DB::table('maintenance_work_orders')->insert(['id'=>$id,'organization_id'=>$org,'vehicle_id'=>$data['vehicle_id'],'number'=>$data['number'],'status'=>!empty($data['scheduled_at'])?'scheduled':'draft','scheduled_at'=>$data['scheduled_at']??null,'workshop_name'=>$data['workshop_name']??null,'version'=>1,'created_at'=>now(),'updated_at'=>now()]);
            foreach ($cycles as $assignment=>$cycle) DB::table('maintenance_work_order_items')->insert(['id'=>$this->id(),'organization_id'=>$org,'work_order_id'=>$id,'assignment_id'=>$assignment,'cycle_id'=>$cycle,'status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
            $this->event($org,$id,$r->user()->id,'created');
        });
        return ApiResponse::success($r,['id'=>$id,'version'=>1],'Work Order Created','',201);
    }
    public function startOrder(Request $r, string $organization, string $order)
    {
        $this->authorize($r,true); $data=$r->validate(['version'=>'required|integer|min:1']); $org=$this->org($r);
        DB::transaction(function () use ($r,$org,$order,$data) {
            $o=$this->row('maintenance_work_orders',$org,$order,true); abort_if((int)$o->version !== (int)$data['version'] || !in_array($o->status,['draft','scheduled'],true),409,'Order version or state conflict.');
            DB::table('maintenance_work_orders')->where('id',$order)->update(['status'=>'in_progress','version'=>$o->version+1,'updated_at'=>now()]); $this->event($org,$order,$r->user()->id,'started');
        });
        return ApiResponse::success($r,['version'=>$data['version']+1,'status'=>'in_progress'],'Work Order Started');
    }
    public function cancelOrder(Request $r, string $organization, string $order)
    {
        $this->authorize($r,true); $data=$r->validate(['version'=>'required|integer|min:1','reason'=>'required|string|max:500']); $org=$this->org($r);
        DB::transaction(function () use ($r,$org,$order,$data) {
            $o=$this->row('maintenance_work_orders',$org,$order,true); abort_if((int)$o->version !== (int)$data['version'] || !in_array($o->status,['draft','scheduled','in_progress'],true),409,'Order version or state conflict.');
            DB::table('maintenance_work_orders')->where('id',$order)->update(['status'=>'cancelled','cancel_reason'=>$data['reason'],'version'=>$o->version+1,'updated_at'=>now()]); $this->event($org,$order,$r->user()->id,'cancelled');
        });
        return ApiResponse::success($r,['version'=>$data['version']+1,'status'=>'cancelled'],'Work Order Cancelled');
    }
    public function completeOrder(Request $r, string $organization, string $order)
    {
        $this->authorize($r,true); $data=$r->validate(['version'=>'required|integer|min:1','performed_at'=>'required|date|before_or_equal:now','odometer_km'=>'required|numeric|min:0|max:1000000000','odometer_source'=>'required|string|max:100','workshop_name'=>'required|string|max:160','completed_item_ids'=>'required|array|min:1|max:30','completed_item_ids.*'=>'required|uuid|distinct','cost_minor'=>'nullable|integer|min:0','currency'=>'required_with:cost_minor|nullable|regex:/^[A-Z]{3}$/','notes'=>'nullable|string|max:4000']);
        $key=$r->header('Idempotency-Key'); if (!is_string($key) || strlen($key)<8 || strlen($key)>255) throw ValidationException::withMessages(['Idempotency-Key'=>['Provide an idempotency key of 8 to 255 characters.']]);
        $hash=hash('sha256',$key); $org=$this->org($r); sort($data['completed_item_ids']); $payloadHash=hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
        $result=DB::transaction(function () use ($r,$org,$order,$data,$hash,$payloadHash) {
            DB::table('organizations')->where('id',$org)->lockForUpdate()->first(); $o=$this->row('maintenance_work_orders',$org,$order,true);
            if ($o->status==='completed') { abort_unless($o->completion_idempotency_hash && hash_equals($o->completion_idempotency_hash,$hash) && $o->completion_payload_hash && hash_equals($o->completion_payload_hash,$payloadHash),409,'Order already completed with another request.'); return ['version'=>$o->version,'status'=>'completed']; }
            abort_if((int)$o->version !== (int)$data['version'] || $o->status!=='in_progress',409,'Order version or state conflict.');
            $items=DB::table('maintenance_work_order_items')->where('organization_id',$org)->where('work_order_id',$order)->whereIn('id',$data['completed_item_ids'])->lockForUpdate()->get();
            abort_if($items->count()!==count($data['completed_item_ids']),422,'Items do not belong to this order.'); $first=true;
            foreach ($items as $item) {
                $assignment=$this->row('vehicle_maintenance_plans',$org,$item->assignment_id,true); $cycle=$this->row('maintenance_service_cycles',$org,$item->cycle_id,true);
                abort_if($cycle->ended_at!==null || $item->status!=='pending',409,'Cycle already completed.');
                abort_if($cycle->serviced_at && CarbonImmutable::parse($data['performed_at'])->lt(CarbonImmutable::parse($cycle->serviced_at)),422,'Retroactive service requires reconciliation, which is not available yet.');
                DB::table('maintenance_service_records')->insert(['id'=>$this->id(),'organization_id'=>$org,'vehicle_id'=>$o->vehicle_id,'work_order_id'=>$order,'work_order_item_id'=>$item->id,'performed_at'=>$data['performed_at'],'odometer_km'=>$data['odometer_km'],'odometer_source'=>$data['odometer_source'],'workshop_name'=>$data['workshop_name'],'cost_minor'=>$first?($data['cost_minor']??null):null,'currency'=>$first?($data['currency']??null):null,'notes'=>$data['notes']??null,'entered_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]); $first=false;
                DB::table('maintenance_service_cycles')->where('id',$cycle->id)->update(['ended_at'=>$data['performed_at'],'updated_at'=>now()]);
                $occurrences=DB::table('alert_occurrences')->where('organization_id',$org)->where('rule_key','maintenance.'.$assignment->id)->where('episode_key',$cycle->id)->where('state','open')->get();
                foreach ($occurrences as $occurrence) {
                    DB::table('alert_occurrences')->where('id',$occurrence->id)->update(['state'=>'resolved','updated_at'=>now()]);
                    DB::table('alert_transitions')->insert(['id'=>$this->id(),'organization_id'=>$org,'occurrence_id'=>$occurrence->id,'action'=>'resolved_by_service','evidence'=>json_encode(['work_order_id'=>$order,'performed_at'=>$data['performed_at']],JSON_THROW_ON_ERROR),'created_at'=>now()]);
                }
                $revision=$this->snapshot($org,$assignment->plan_id);
                DB::table('maintenance_service_cycles')->insert(['id'=>$this->id(),'organization_id'=>$org,'assignment_id'=>$assignment->id,'ordinal'=>$cycle->ordinal+1,'serviced_at'=>$data['performed_at'],'interval_snapshot'=>$revision->interval,'thresholds_snapshot'=>$revision->thresholds,'baseline_quality'=>'unknown_baseline','created_at'=>now(),'updated_at'=>now()]);
                DB::table('maintenance_work_order_items')->where('id',$item->id)->update(['status'=>'completed','updated_at'=>now()]);
            }
            DB::table('maintenance_work_order_items')->where('work_order_id',$order)->where('status','pending')->update(['status'=>'not_completed','updated_at'=>now()]);
            DB::table('maintenance_work_orders')->where('id',$order)->update(['status'=>'completed','version'=>$o->version+1,'completion_idempotency_hash'=>$hash,'completion_payload_hash'=>$payloadHash,'updated_at'=>now()]); $this->event($org,$order,$r->user()->id,'completed');
            app(\App\Services\Maintenance\EvaluateMaintenance::class)->vehicle($org,$o->vehicle_id);
            return ['version'=>$o->version+1,'status'=>'completed'];
        });
        return ApiResponse::success($r,$result,'Work Order Completed','Completed cycles are resolved; new-cycle distance is evaluated from the actual service time.');
    }
    public function records(Request $r, string $organization, string $vehicle)
    {
        $this->authorize($r); $org=$this->org($r); $this->row('vehicles',$org,$vehicle);
        return ApiResponse::success($r,['items'=>DB::table('maintenance_service_records')->where('organization_id',$org)->where('vehicle_id',$vehicle)->orderByDesc('performed_at')->limit(100)->get()],'Service Records Retrieved');
    }
    public function dueSummary(Request $r, string $organization)
    {
        $this->authorize($r); $org=$this->org($r);
        $v=$r->validate(['vehicle_id'=>'nullable|uuid']);
        $now=CarbonImmutable::now('UTC');
        $statusService=app(\App\Services\Maintenance\MaintenanceStatus::class);
        $items=DB::table('vehicle_maintenance_plans as a')
            ->join('vehicles as v',fn($j)=>$j->on('v.id','=','a.vehicle_id')->on('v.organization_id','=','a.organization_id'))
            ->join('maintenance_plans as p','p.id','=','a.plan_id')
            ->join('maintenance_service_cycles as c','c.assignment_id','=','a.id')
            ->where('a.organization_id',$org)
            ->where('a.status','active')
            ->where('v.status','<>','archived')
            ->whereNull('c.ended_at')
            ->when($v['vehicle_id'] ?? null,fn($q,$vid)=>$q->where('a.vehicle_id',$vid))
            ->orderBy('v.registration_number')
            ->orderBy('p.name')
            ->limit(200)
            ->get(['a.id','a.vehicle_id','a.status','v.registration_number','v.make','v.model','p.name','p.service_type','c.serviced_at','c.interval_snapshot','c.baseline_quality','c.carry_in_distance_m','c.carry_in_as_of'])
            ->map(function ($row) use ($statusService,$org,$now) {
                $eval=$statusService->cycle($org,$row->vehicle_id,$row,$now);
                return [
                    'id'=>$row->id,
                    'vehicle_id'=>$row->vehicle_id,
                    'registration_number'=>$row->registration_number,
                    'make'=>$row->make,
                    'model'=>$row->model,
                    'name'=>$row->name,
                    'service_type'=>$row->service_type,
                    'status'=>$row->status,
                    'remaining_km'=>$eval['remaining_km'],
                    'remaining_days'=>$eval['remaining_days'],
                    'due_date'=>$eval['due_date'],
                    'baseline_quality'=>$row->baseline_quality,
                    'distance_status'=>$eval['distance_status'],
                ];
            });
        return ApiResponse::success($r,['items'=>$items],'Maintenance Due Summary Retrieved','Distance is an estimated lower bound; missing usage may make service due sooner.');
    }
}
