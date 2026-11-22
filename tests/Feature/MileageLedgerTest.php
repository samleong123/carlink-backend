<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Mileage\SpeedLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MileageLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user=User::create(['email_normalized'=>'mileage@example.test','display_name'=>'Owner']);
        $org=Organization::create(['name'=>'Mileage Fleet','slug'=>'mileage']);
        Membership::create(['organization_id'=>$org->id,'user_id'=>$user->id,'role'=>'owner','status'=>'active']);
        $vehicle=(string) Str::uuid(); $device=(string) Str::uuid(); $installation=(string) Str::uuid(); $boot=(string) Str::uuid();
        $start=CarbonImmutable::now('UTC')->subHours(2)->startOfMinute();
        DB::table('vehicles')->insert(['id'=>$vehicle,'organization_id'=>$org->id,'registration_number'=>'ABC1','registration_normalized'=>'ABC1','registration_country'=>'MY','created_at'=>now(),'updated_at'=>now()]);
        DB::table('devices')->insert(['id'=>$device,'organization_id'=>$org->id,'serial_number'=>'pi-1','status'=>'active','broker_principal'=>'mileage-pi','created_at'=>now(),'updated_at'=>now()]);
        DB::table('device_installations')->insert(['id'=>$installation,'organization_id'=>$org->id,'device_id'=>$device,'vehicle_id'=>$vehicle,'effective_from'=>$start->subHour(),'created_at'=>now(),'updated_at'=>now()]);
        return [$user,$org,$vehicle,$device,$installation,$boot,$start];
    }

    private function inbox(object $org, string $device, string $installation, string $boot, CarbonImmutable $at, int $uptime, int $sequence, ?string $sampleId=null, float $speed=60, ?string $pid='010D'): string
    {
        $id=(string) Str::uuid(); $eventId=(string) Str::uuid(); $sampleId ??= (string) Str::uuid();
        $stamp=$at->format('Y-m-d\TH:i:s.v\Z');
        $event=['schema_version'=>'1.0','message_type'=>'telemetry','event_id'=>$eventId,'device_id'=>$device,'installation_id'=>$installation,'boot_id'=>$boot,'sequence'=>$sequence,'occurred_at'=>$stamp,'uptime_ms'=>$uptime,'clock'=>['synchronized'=>true,'estimated_error_ms'=>100], 'payload'=>['vehicle'=>['speed_kmh'=>['value'=>$speed,'quality'=>'valid','pid'=>$pid,'sample_id'=>$sampleId,'observed_at'=>$stamp,'observed_uptime_ms'=>$uptime]]]];
        $json=json_encode($event,JSON_THROW_ON_ERROR);
        DB::table('telemetry_inbox')->insert(['id'=>$id,'organization_id'=>$org->id,'device_id'=>$device,'installation_id'=>$installation,'event_id'=>$eventId,'boot_id'=>$boot,'sequence'=>$sequence,'occurred_at'=>$at->format('Y-m-d H:i:s.uP'),'received_at'=>now(),'payload_sha256'=>hash('sha256',$json),'raw_payload'=>$json,'state'=>'pending_projection']);
        return $id;
    }

    public function test_speed_distance_is_duplicate_safe_and_late_sample_supersedes_segment(): void
    {
        [$user,$org,$vehicle,$device,$installation,$boot,$start]=$this->fixture();
        $ledger=app(SpeedLedger::class); $ids=[];
        for ($i=0;$i<=12;$i++) { $ids[]=$this->inbox($org,$device,$installation,$boot,$start->addSeconds($i*5),$i*5000,$i); $this->assertSame('stored',$ledger->project($ids[$i])); }
        $this->assertSame('duplicate',$ledger->project($ids[5]));
        $base='/api/v1/organizations/'.$org->id.'/vehicles/'.$vehicle;
        $this->actingAs($user)->postJson($base.'/odometer-observations',['reading_m'=>85000000,'observed_at'=>$start->toIso8601String(),'reason'=>'Dashboard reading'])->assertCreated();
        $this->getJson($base.'/mileage')->assertOk()->assertJsonPath('data.accepted_distance_m',1000)->assertJsonPath('data.estimated_odometer_m',85001000);
        $late=$this->inbox($org,$device,$installation,$boot,$start->addMilliseconds(2500),2500,99);
        $this->assertSame('stored',$ledger->project($late));
        $this->getJson($base.'/mileage')->assertOk()->assertJsonPath('data.accepted_distance_m',1000);
        $this->assertSame(1,DB::table('vehicle_distance_segments')->whereNotNull('superseded_at')->count());
        $this->assertSame(13,DB::table('vehicle_distance_segments')->whereNull('superseded_at')->count());
    }

    public function test_long_gap_is_unknown_and_cross_tenant_access_is_denied(): void
    {
        [$user,$org,$vehicle,$device,$installation,$boot,$start]=$this->fixture(); $ledger=app(SpeedLedger::class);
        $ledger->project($this->inbox($org,$device,$installation,$boot,$start,0,0));
        $ledger->project($this->inbox($org,$device,$installation,$boot,$start->addMinute(),60000,1));
        $base='/api/v1/organizations/'.$org->id.'/vehicles/'.$vehicle;
        $this->actingAs($user)->getJson($base.'/mileage')->assertOk()->assertJsonPath('data.accepted_distance_m',0)->assertJsonPath('data.unknown_interval_count',1)->assertJsonPath('data.coverage_complete',false);
        $this->getJson($base.'/mileage/segments')->assertOk()->assertJsonPath('data.items.0.source','unknown')->assertJsonPath('data.items.0.distance_m',null);
        $other=User::create(['email_normalized'=>'other-mileage@example.test','display_name'=>'Other']); $otherOrg=Organization::create(['name'=>'Other','slug'=>'other-mileage']);
        Membership::create(['organization_id'=>$otherOrg->id,'user_id'=>$other->id,'role'=>'owner','status'=>'active']);
        $this->actingAs($other)->getJson('/api/v1/organizations/'.$otherOrg->id.'/vehicles/'.$vehicle.'/mileage')->assertNotFound();
    }

    public function test_reused_sample_identifier_cannot_change_value(): void
    {
        [, $org,$vehicle,$device,$installation,$boot,$start]=$this->fixture(); $ledger=app(SpeedLedger::class); $sample=(string) Str::uuid();
        $ledger->project($this->inbox($org,$device,$installation,$boot,$start,0,0,$sample,60));
        $conflict=$this->inbox($org,$device,$installation,$boot,$start,0,1,$sample,70);
        try { $ledger->project($conflict); $this->fail('Conflicting sample accepted.'); }
        catch (\DomainException $e) { $this->assertSame(1,DB::table('mileage_speed_observations')->count()); }
    }

    public function test_baseline_crossing_a_segment_integrates_only_post_baseline_distance(): void
    {
        [$user,$org,$vehicle,$device,$installation,$boot,$start]=$this->fixture(); $ledger=app(SpeedLedger::class);
        $ledger->project($this->inbox($org,$device,$installation,$boot,$start,0,0));
        $ledger->project($this->inbox($org,$device,$installation,$boot,$start->addSeconds(5),5000,1));
        $base='/api/v1/organizations/'.$org->id.'/vehicles/'.$vehicle;
        $this->actingAs($user)->postJson($base.'/odometer-observations',['reading_m'=>100000,'observed_at'=>$start->addSeconds(2)->toIso8601String(),'reason'=>'Dashboard reading'])->assertCreated();
        $this->getJson($base.'/mileage')->assertOk()->assertJsonPath('data.estimated_odometer_m',100050)->assertJsonPath('data.baseline_alignment','integrated_segment_boundary');
    }

    public function test_only_obd_vehicle_speed_pid_is_projected(): void
    {
        [, $org,$vehicle,$device,$installation,$boot,$start]=$this->fixture(); $ledger=app(SpeedLedger::class);
        $this->assertSame('unusable',$ledger->project($this->inbox($org,$device,$installation,$boot,$start,0,0,null,60,null)));
        $this->assertSame('unusable',$ledger->project($this->inbox($org,$device,$installation,$boot,$start->addSeconds(1),1000,1,null,60,'010C')));
        $this->assertSame('stored',$ledger->project($this->inbox($org,$device,$installation,$boot,$start->addSeconds(2),2000,2,null,60,'010d')));
        $this->assertSame(1,DB::table('mileage_speed_observations')->where('vehicle_id',$vehicle)->count());
    }
}
