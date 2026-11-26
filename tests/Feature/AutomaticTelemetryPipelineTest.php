<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Broker\BrokerOutbox;
use App\Services\Broker\BrokerPublisher;
use App\Services\Telemetry\TelemetryInbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomaticTelemetryPipelineTest extends TestCase
{
    use RefreshDatabase;
    private function fixture(): array
    {
        config(['broker.verified_topic_acls'=>true]);
        $org=Organization::create(['name'=>'Automatic','slug'=>'automatic']); $user=User::create(['email_normalized'=>'automatic@example.test','display_name'=>'Owner']);
        Membership::create(['organization_id'=>$org->id,'user_id'=>$user->id,'role'=>'owner','status'=>'active']);
        $vehicle=(string)Str::uuid(); $device=(string)Str::uuid(); $installation=(string)Str::uuid(); $boot=(string)Str::uuid(); $start=CarbonImmutable::now('UTC')->subHour()->startOfMinute();
        DB::table('vehicles')->insert(['id'=>$vehicle,'organization_id'=>$org->id,'registration_number'=>'AUTO1','registration_normalized'=>'AUTO1','registration_country'=>'MY','created_at'=>now(),'updated_at'=>now()]);
        DB::table('devices')->insert(['id'=>$device,'organization_id'=>$org->id,'serial_number'=>'auto-pi','broker_principal'=>'auto-pi','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        DB::table('device_installations')->insert(['id'=>$installation,'organization_id'=>$org->id,'device_id'=>$device,'vehicle_id'=>$vehicle,'effective_from'=>$start->subDay(),'created_at'=>now(),'updated_at'=>now()]);
        return [$org,$user,$vehicle,$device,$installation,$boot,$start];
    }
    private function message(array $fixture, int $i, float $speed=60): array
    {
        [$org,$user,$vehicle,$device,$installation,$boot,$start]=$fixture; $time=$start->addSeconds($i*5)->format('Y-m-d\TH:i:s.v\Z');
        return ['schema_version'=>'1.0','message_type'=>'telemetry','event_id'=>(string)Str::uuid(),'device_id'=>$device,'installation_id'=>$installation,'boot_id'=>$boot,'sequence'=>$i,'uptime_ms'=>$i*5000,'occurred_at'=>$time,'sent_at'=>$time,'edge_version'=>'1.0','config_version'=>1,'clock'=>['source'=>'gnss','synchronized'=>true,'estimated_error_ms'=>100],
            'payload'=>['vehicle'=>['speed_kmh'=>['value'=>$speed,'quality'=>'valid','pid'=>'010D','ecu'=>'7E8','sample_id'=>(string)Str::uuid(),'observed_at'=>$time,'observed_uptime_ms'=>$i*5000]]]];
    }
    private function receive(array $fixture, array $message): string
    {
        return app(TelemetryInbox::class)->receive("fleet.v1.{$fixture[0]->id}.{$fixture[3]}.up.telemetry",json_encode($message,JSON_THROW_ON_ERROR));
    }
    public function test_accepted_telemetry_automatically_projects_one_kilometre_without_fuel(): void
    {
        $f=$this->fixture();
        for ($i=0;$i<=12;$i++) { $message=$this->message($f,$i); $this->assertSame('stored',$this->receive($f,$message)); }
        $this->artisan('telemetry:project-pending')->assertSuccessful(); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertDatabaseCount('telemetry_samples',13); $this->assertDatabaseCount('broker_outbox',13); $this->assertDatabaseCount('journeys',1);
        $this->actingAs($f[1])->getJson("/api/v1/organizations/{$f[0]->id}/vehicles/{$f[2]}/mileage")->assertJsonPath('data.accepted_distance_m',1000);
        $this->assertSame('duplicate',$this->receive($f,$message));
        $this->artisan('telemetry:project-pending')->assertSuccessful(); $this->assertDatabaseCount('telemetry_samples',13);
        $ack=json_decode(DB::table('broker_outbox')->orderByDesc('updated_at')->first()->payload,true);
        $this->assertSame('ingest_ack',$ack['message_type']); $this->assertArrayNotHasKey('request_id',$ack);
    }
    public function test_late_data_cannot_replace_latest_state_and_is_flagged_for_journey_rebuild(): void
    {
        $f=$this->fixture(); $this->receive($f,$this->message($f,2)); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $latest=DB::table('vehicle_latest_states')->first();
        $late=$this->message($f,1); $this->receive($f,$late); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertSame($latest->sample_id,DB::table('vehicle_latest_states')->first()->sample_id);
        $this->assertDatabaseHas('telemetry_inbox',['event_id'=>$late['event_id'],'state'=>'projected_requires_rebuild']);
        $this->assertSame(83, (int) round(DB::table('vehicle_distance_segments')->whereNull('superseded_at')->sum('distance_m')));
    }
    public function test_invalid_numeric_strings_and_missing_value_zero_are_not_equivalent(): void
    {
        $f=$this->fixture(); $message=$this->message($f,1); $message['payload']['vehicle']['speed_kmh']['value']='60';
        $this->assertSame('quarantined',$this->receive($f,$message));
        $message=$this->message($f,2,0); $this->assertSame('stored',$this->receive($f,$message));
        $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertSame(0.0,(float)DB::table('telemetry_samples')->value('speed_kmh'));
        $message=$this->message($f,3); $message['payload']['vehicle']['speed_kmh']['quality']='unavailable'; $message['payload']['vehicle']['speed_kmh']['value']=null;
        $this->assertSame('stored',$this->receive($f,$message)); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertDatabaseHas('telemetry_samples',['speed_kmh'=>null]);
    }
    public function test_failed_publisher_retains_ack_and_duplicate_rearms_publication(): void
    {
        $f=$this->fixture(); $message=$this->message($f,1); $this->receive($f,$message);
        $publisher=new class implements BrokerPublisher {
            public bool $fail=true; public array $ids=[];
            public function publish(string $routingKey,string $payload,string $messageId,int $ttlMs): void { $this->ids[]=$messageId; if ($this->fail) throw new \RuntimeException('simulated'); }
        };
        $outbox=app(BrokerOutbox::class);
        try { $outbox->publishOne($publisher); $this->fail('Failure not raised.'); } catch (\RuntimeException $e) { $this->assertNull(DB::table('broker_outbox')->value('published_at')); }
        $publisher->fail=false; $this->receive($f,$message); $this->assertTrue($outbox->publishOne($publisher));
        $this->assertNotNull(DB::table('broker_outbox')->value('published_at'));
        $this->receive($f,$message); $this->assertTrue($outbox->publishOne($publisher));
        $this->assertCount(1,array_unique($publisher->ids));
    }
    public function test_queries_are_bounded_and_driver_cannot_read_fleet_telemetry(): void
    {
        $f=$this->fixture(); $this->receive($f,$this->message($f,1)); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $base="/api/v1/organizations/{$f[0]->id}/vehicles/{$f[2]}/telemetry";
        $query='?from='.urlencode($f[6]->toIso8601String()).'&to='.urlencode($f[6]->addHour()->toIso8601String());
        $this->actingAs($f[1])->getJson($base.$query)->assertOk()->assertJsonCount(1,'data.items');
        $this->getJson($base)->assertUnprocessable();
        // Multi-day (>24h) windows are permitted, while inverted ranges (to <= from) are rejected
        $this->getJson($base.'?from='.urlencode($f[6]->toIso8601String()).'&to='.urlencode($f[6]->addDays(2)->toIso8601String()))->assertOk()->assertJsonCount(1,'data.items');
        $this->getJson($base.'?from='.urlencode($f[6]->toIso8601String()).'&to='.urlencode($f[6]->subMinute()->toIso8601String()))->assertUnprocessable();
        Membership::where('organization_id',$f[0]->id)->update(['role'=>'driver']);
        $this->getJson($base.$query)->assertForbidden();
    }

    public function test_maintenance_uses_boundary_integral_coalesces_stages_and_supersedes_corrected_due_alert(): void
    {
        $f=$this->fixture(); $base='/api/v1/organizations/'.$f[0]->id; $this->actingAs($f[1]);
        $this->receive($f,$this->message($f,0,0)); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $plan=$this->postJson($base.'/maintenance-plans',['name'=>'Short test service','service_type'=>'oil','interval'=>['distance_km'=>0.08,'due_when'=>'any'],'thresholds'=>[
            ['key'=>'soon','severity'=>'warning','remaining_km_lte'=>0.04,'match'=>'any','channels'=>['in_app']],
            ['key'=>'due','severity'=>'high','remaining_km_lte'=>0,'match'=>'any','channels'=>['in_app']],
        ]])->assertCreated()->json('data.id');
        $this->postJson($base.'/vehicles/'.$f[2].'/maintenance-plan-assignments',['plan_id'=>$plan,'last_serviced_at'=>$f[6]->addSeconds(2)->toIso8601String()])->assertCreated();
        $this->receive($f,$this->message($f,2,72)); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $status=$this->getJson($base.'/vehicles/'.$f[2].'/maintenance')->assertOk();
        $this->assertEqualsWithDelta(96,$status->json('data.items.0.usage_since_service_m'),0.000001);
        $this->assertDatabaseCount('alert_occurrences',2); $this->assertDatabaseCount('notification_records',1);
        $this->assertDatabaseHas('notification_records',['severity'=>'high']);
        $this->artisan('maintenance:evaluate')->assertSuccessful(); $this->assertDatabaseCount('notification_records',1);
        $this->receive($f,$this->message($f,1,0)); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertDatabaseHas('alert_occurrences',['threshold_key'=>'due','state'=>'superseded']);
        $this->assertDatabaseHas('alert_transitions',['action'=>'superseded_by_correction']);
        $this->assertEqualsWithDelta(50,$this->getJson($base.'/vehicles/'.$f[2].'/maintenance')->json('data.items.0.usage_since_service_m'),0.000001);
        $this->assertDatabaseCount('notification_records',1);
        $this->receive($f,$this->message($f,3)); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertDatabaseHas('alert_occurrences',['threshold_key'=>'due','state'=>'open']);
        $this->assertDatabaseHas('alert_transitions',['action'=>'reactivated']);
        $this->assertDatabaseCount('notification_records',1);
    }

    public function test_pre_monitoring_service_usage_stays_unknown_until_explicit_carry_in(): void
    {
        $f=$this->fixture(); $base='/api/v1/organizations/'.$f[0]->id; $this->actingAs($f[1]);
        $this->receive($f,$this->message($f,0)); $this->receive($f,$this->message($f,1)); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $body=['name'=>'Oil','service_type'=>'oil','interval'=>['distance_km'=>1,'due_when'=>'any'],'thresholds'=>[['key'=>'due','severity'=>'critical','remaining_km_lte'=>0,'match'=>'any','channels'=>['in_app']]]];
        $plan=$this->postJson($base.'/maintenance-plans',$body)->assertCreated()->json('data.id');
        $this->postJson($base.'/vehicles/'.$f[2].'/maintenance-plan-assignments',['plan_id'=>$plan,'last_serviced_at'=>$f[6]->subDay()->toIso8601String()])->assertCreated();
        $this->getJson($base.'/vehicles/'.$f[2].'/maintenance')->assertJsonPath('data.items.0.remaining_km',null);
        $body['name']='Tyres'; $plan=$this->postJson($base.'/maintenance-plans',$body)->assertCreated()->json('data.id');
        $this->postJson($base.'/vehicles/'.$f[2].'/maintenance-plan-assignments',['plan_id'=>$plan,'last_serviced_at'=>$f[6]->subDay()->toIso8601String(),'carry_in_distance_m'=>950,'carry_in_as_of'=>$f[6]->toIso8601String(),'carry_in_reason'=>'Verified onboarding/service readings'])->assertCreated();
        $this->assertDatabaseCount('alert_occurrences',1);
    }

    public function test_duplicate_during_publish_does_not_mark_rearmed_generation_delivered(): void
    {
        $f=$this->fixture(); $message=$this->message($f,1); $this->receive($f,$message);
        $publisher=new class($f,$message) implements BrokerPublisher {
            public int $calls=0;
            public function __construct(private array $fixture,private array $message) {}
            public function publish(string $routingKey,string $payload,string $messageId,int $ttlMs): void {
                if (++$this->calls === 1) app(TelemetryInbox::class)->receive("fleet.v1.{$this->fixture[0]->id}.{$this->fixture[3]}.up.telemetry",json_encode($this->message,JSON_THROW_ON_ERROR));
            }
        };
        $outbox=app(BrokerOutbox::class);
        $this->assertTrue($outbox->publishOne($publisher)); $this->assertNull(DB::table('broker_outbox')->value('published_at'));
        $this->assertTrue($outbox->publishOne($publisher)); $this->assertNotNull(DB::table('broker_outbox')->value('published_at'));
        $this->assertSame(2,$publisher->calls);
    }

    public function test_service_completion_resolves_old_alert_and_integrates_post_service_usage(): void
    {
        $f=$this->fixture(); $base='/api/v1/organizations/'.$f[0]->id; $this->actingAs($f[1]);
        $this->receive($f,$this->message($f,0)); $this->receive($f,$this->message($f,2)); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $plan=$this->postJson($base.'/maintenance-plans',['name'=>'Service test','service_type'=>'oil','interval'=>['distance_km'=>0.12,'due_when'=>'any'],'thresholds'=>[['key'=>'due','severity'=>'critical','remaining_km_lte'=>0,'match'=>'any','channels'=>['in_app']]]])->assertCreated()->json('data.id');
        $assignment=$this->postJson($base.'/vehicles/'.$f[2].'/maintenance-plan-assignments',['plan_id'=>$plan,'last_serviced_at'=>$f[6]->toIso8601String()])->assertCreated()->json('data.id');
        $old=DB::table('alert_occurrences')->first(); $this->assertSame('open',$old->state);
        $order=$this->postJson($base.'/maintenance/work-orders',['vehicle_id'=>$f[2],'number'=>'AUTO-WO1','assignment_ids'=>[$assignment]])->assertCreated()->json('data.id');
        $item=$this->getJson($base.'/maintenance/work-orders/'.$order)->assertOk()->json('data.items.0.id');
        $this->postJson($base.'/maintenance/work-orders/'.$order.'/start',['version'=>1])->assertOk();
        $body=['version'=>2,'performed_at'=>$f[6]->addSeconds(5)->toIso8601String(),'odometer_km'=>12345,'odometer_source'=>'attested_dashboard','workshop_name'=>'Test','completed_item_ids'=>[$item]];
        $this->postJson($base.'/maintenance/work-orders/'.$order.'/complete',$body,['Idempotency-Key'=>'auto-service-completion'])->assertOk();
        $this->assertDatabaseHas('alert_occurrences',['id'=>$old->id,'state'=>'resolved']);
        $this->assertDatabaseHas('alert_transitions',['occurrence_id'=>$old->id,'action'=>'resolved_by_service']);
        $this->assertEqualsWithDelta(83.333333,$this->getJson($base.'/vehicles/'.$f[2].'/maintenance')->json('data.items.0.usage_since_service_m'),0.00001);
        $this->assertDatabaseCount('notification_records',1);
        $this->receive($f,$this->message($f,3)); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertDatabaseCount('notification_records',2); $this->assertDatabaseCount('alert_occurrences',2);
    }

    public function test_email_only_threshold_does_not_claim_or_create_in_app_delivery(): void
    {
        $f=$this->fixture(); $base='/api/v1/organizations/'.$f[0]->id; $this->actingAs($f[1]);
        $this->receive($f,$this->message($f,0)); $this->receive($f,$this->message($f,1)); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $plan=$this->postJson($base.'/maintenance-plans',['name'=>'Email only','service_type'=>'oil','interval'=>['distance_km'=>0.01,'due_when'=>'any'],'thresholds'=>[['key'=>'due','severity'=>'critical','remaining_km_lte'=>0,'match'=>'any','channels'=>['email']]]])->assertCreated()->json('data.id');
        $this->postJson($base.'/vehicles/'.$f[2].'/maintenance-plan-assignments',['plan_id'=>$plan,'last_serviced_at'=>$f[6]->toIso8601String()])->assertCreated();
        $this->assertDatabaseCount('alert_occurrences',1); $this->assertDatabaseCount('notification_records',0);
    }

    public function test_recommended_contract_fixture_is_accepted_and_projects_gps(): void
    {
        $f=$this->fixture();
        $message=json_decode(file_get_contents(base_path('contracts/fixtures/v1/telemetry.json')),true,flags:JSON_THROW_ON_ERROR);
        $message['device_id']=$f[3]; $message['installation_id']=$f[4]; $message['boot_id']=$f[5];
        $at=CarbonImmutable::parse($message['occurred_at']);
        DB::table('device_installations')->where('id',$f[4])->update(['effective_from'=>$at->subDay()]);
        CarbonImmutable::setTestNow($at->addMinute());
        try {
            $this->assertSame('stored',$this->receive($f,$message));
            $this->artisan('telemetry:project-pending')->assertSuccessful();
            $sample=DB::table('telemetry_samples')->first();
            $this->assertSame(52.0,(float)$sample->speed_kmh); $this->assertSame(2100.0,(float)$sample->engine_rpm);
            $this->assertSame(3.139,(float)$sample->latitude); $this->assertSame(101.6869,(float)$sample->longitude);
            $ack=json_decode(DB::table('broker_outbox')->value('payload'),true);
            $this->assertSame(['schema_version','message_type','device_id','acknowledged_at','results'],array_keys($ack));
        } finally { CarbonImmutable::setTestNow(); }
    }

    public function test_unsynchronized_clock_cannot_replace_trusted_latest_values(): void
    {
        $f=$this->fixture(); $this->receive($f,$this->message($f,1)); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $latest=DB::table('vehicle_latest_states')->first();
        $message=$this->message($f,2); $message['clock']['synchronized']=false;
        $this->assertSame('stored',$this->receive($f,$message)); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertSame($latest->sample_id,DB::table('vehicle_latest_states')->value('sample_id'));
        $this->assertDatabaseHas('telemetry_samples',['speed_kmh'=>null]); $this->assertDatabaseCount('mileage_speed_observations',1);
    }
}
