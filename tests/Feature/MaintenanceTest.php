<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private function fleet(string $slug): array
    {
        $user=User::create(['email_normalized'=>$slug.'@example.test','display_name'=>$slug]);
        $org=Organization::create(['name'=>$slug,'slug'=>$slug,'timezone'=>'Asia/Kuala_Lumpur']);
        Membership::create(['organization_id'=>$org->id,'user_id'=>$user->id,'role'=>'owner','status'=>'active']);
        $this->actingAs($user);
        $vehicle=$this->postJson('/api/v1/organizations/'.$org->id.'/vehicles',['registration_number'=>'ABC123','registration_country'=>'MY'])->json('data.item.id');
        return [$user,$org,$vehicle];
    }
    private function planInput(string $name): array
    {
        return ['name'=>$name,'service_type'=>'oil','interval'=>['distance_km'=>10000,'calendar_months'=>1,'due_when'=>'any'],'thresholds'=>[
            ['key'=>'soon','severity'=>'warning','remaining_km_lte'=>500,'remaining_days_lte'=>7,'match'=>'any','channels'=>['in_app']],
            ['key'=>'due','severity'=>'high','remaining_km_lte'=>0,'remaining_days_lte'=>0,'match'=>'any','channels'=>['in_app']],
        ]];
    }
    public function test_plans_validate_thresholds_and_enforce_tenant_isolation(): void
    {
        [$user,$a,$vehicle]=$this->fleet('fleet-a'); $path='/api/v1/organizations/'.$a->id;
        $input=$this->planInput('Oil'); $input['thresholds'][1]['key']='soon'; $this->postJson($path.'/maintenance-plans',$input)->assertStatus(422);
        $input=$this->planInput('Oil'); unset($input['interval']['distance_km']); $this->postJson($path.'/maintenance-plans',$input)->assertStatus(422);
        $plan=$this->postJson($path.'/maintenance-plans',$this->planInput('Oil'))->assertCreated()->json('data.id');
        [$other,$b,$otherVehicle]=$this->fleet('fleet-b');
        $this->getJson('/api/v1/organizations/'.$b->id.'/maintenance-plans/'.$plan)->assertNotFound();
        $this->postJson('/api/v1/organizations/'.$b->id.'/vehicles/'.$otherVehicle.'/maintenance-plan-assignments',['plan_id'=>$plan])->assertNotFound();
    }
    public function test_calendar_clamps_month_end_and_distance_stays_unknown(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-02-15T00:00:00Z'));
        [$user,$org,$vehicle]=$this->fleet('calendar'); $path='/api/v1/organizations/'.$org->id;
        $plan=$this->postJson($path.'/maintenance-plans',$this->planInput('Oil'))->assertCreated()->json('data.id');
        $this->postJson($path.'/vehicles/'.$vehicle.'/maintenance-plan-assignments',['plan_id'=>$plan,'last_serviced_at'=>'2026-01-31T02:00:00Z'])->assertCreated();
        $this->getJson($path.'/vehicles/'.$vehicle.'/maintenance')->assertOk()->assertJsonPath('data.items.0.due_date','2026-02-28')->assertJsonPath('data.items.0.remaining_km',null)->assertJsonPath('data.items.0.baseline_quality','unknown_baseline');
    }
    public function test_partial_completion_resets_only_selected_cycle_and_is_idempotent(): void
    {
        [$user,$org,$vehicle]=$this->fleet('orders'); $base='/api/v1/organizations/'.$org->id; $assignments=[];
        foreach (['Oil','Tyres'] as $name) {
            $plan=$this->postJson($base.'/maintenance-plans',$this->planInput($name))->assertCreated()->json('data.id');
            $assignments[]=$this->postJson($base.'/vehicles/'.$vehicle.'/maintenance-plan-assignments',['plan_id'=>$plan])->assertCreated()->json('data.id');
        }
        $order=$this->postJson($base.'/maintenance/work-orders',['vehicle_id'=>$vehicle,'number'=>'WO-1','assignment_ids'=>$assignments])->assertCreated()->json('data.id');
        $this->postJson($base.'/maintenance/work-orders',['vehicle_id'=>$vehicle,'number'=>'WO-2','assignment_ids'=>[$assignments[0]]])->assertStatus(409);
        $item=$this->getJson($base.'/maintenance/work-orders/'.$order)->assertOk()->json('data.items.0.id');
        $this->postJson($base.'/maintenance/work-orders/'.$order.'/start',['version'=>1])->assertOk();
        $body=['version'=>2,'performed_at'=>now()->subMinute()->toIso8601String(),'odometer_km'=>12345,'odometer_source'=>'dashboard','workshop_name'=>'Workshop','completed_item_ids'=>[$item]];
        $headers=['Idempotency-Key'=>'unique-key-123'];
        $this->postJson($base.'/maintenance/work-orders/'.$order.'/complete',$body,$headers)->assertOk()->assertJsonPath('data.status','completed');
        $this->postJson($base.'/maintenance/work-orders/'.$order.'/complete',$body,$headers)->assertOk();
        $changed=$body; $changed['notes']='Different request'; $this->postJson($base.'/maintenance/work-orders/'.$order.'/complete',$changed,$headers)->assertStatus(409);
        $this->assertSame(1,DB::table('maintenance_service_records')->count());
        $this->assertSame(1,DB::table('maintenance_service_cycles')->whereNotNull('ended_at')->count());
        $this->assertSame(2,DB::table('maintenance_service_cycles')->whereNull('ended_at')->count());
        $this->assertSame(1,DB::table('maintenance_action_events')->where('event','completed')->count());
    }
    public function test_new_revision_applies_only_to_the_next_cycle(): void
    {
        [$user,$org,$vehicle]=$this->fleet('revision'); $base='/api/v1/organizations/'.$org->id; $input=$this->planInput('Oil');
        $plan=$this->postJson($base.'/maintenance-plans',$input)->assertCreated()->json('data.id');
        $this->postJson($base.'/vehicles/'.$vehicle.'/maintenance-plan-assignments',['plan_id'=>$plan])->assertCreated();
        $input['interval']['calendar_months']=6;
        $this->postJson($base.'/maintenance-plans/'.$plan.'/revisions',$input)->assertCreated()->assertJsonPath('data.effective_policy','next_cycle');
        $this->getJson($base.'/vehicles/'.$vehicle.'/maintenance')->assertOk()->assertJsonPath('data.items.0.interval.calendar_months',1);
    }
}
