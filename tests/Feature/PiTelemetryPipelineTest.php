<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Telemetry\TelemetryInbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PiTelemetryPipelineTest extends TestCase
{
    use RefreshDatabase;
    private function fixture(): array
    {
        config(['broker.verified_topic_acls'=>true]);
        $org=Organization::create(['name'=>'Pi','slug'=>'pi']); $user=User::create(['email_normalized'=>'pi@example.test','display_name'=>'Owner']);
        Membership::create(['organization_id'=>$org->id,'user_id'=>$user->id,'role'=>'owner','status'=>'active']);
        $vehicle=(string)Str::uuid(); $device=(string)Str::uuid(); $installation=(string)Str::uuid(); $at=CarbonImmutable::now('UTC')->subHour()->startOfMinute();
        DB::table('vehicles')->insert(['id'=>$vehicle,'organization_id'=>$org->id,'registration_number'=>'VEH-001','registration_normalized'=>'VEH001','registration_country'=>'MY','fuel_type'=>'petrol','created_at'=>now(),'updated_at'=>now()]);
        DB::table('devices')->insert(['id'=>$device,'organization_id'=>$org->id,'serial_number'=>'IOT-001','broker_principal'=>$device,'status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        DB::table('device_installations')->insert(['id'=>$installation,'organization_id'=>$org->id,'device_id'=>$device,'vehicle_id'=>$vehicle,'effective_from'=>$at->subDay(),'created_at'=>now(),'updated_at'=>now()]);
        return [$org,$user,$vehicle,$device,$installation,$at];
    }
    private function packet(array $f,int $seconds=0): array
    {
        $v=json_decode(file_get_contents(base_path('contracts/fixtures/v1/pi_telemetry.json')),true,flags:JSON_THROW_ON_ERROR);
        $v['event_id']=(string)Str::uuid(); $v['timestamp']=$f[5]->addSeconds($seconds)->format('Y-m-d\TH:i:s\Z'); $v['gps']['timestamp']=$v['timestamp'];
        return $v;
    }
    private function receive(array $f,array $m): string { return app(TelemetryInbox::class)->receive("fleet.v1.{$f[0]->id}.{$f[3]}.up.telemetry",json_encode($m,JSON_THROW_ON_ERROR)); }
    public function test_pi_packets_project_without_fabricated_metadata_or_driver_authority(): void
    {
        $f=$this->fixture();
        for ($i=0;$i<=12;$i++) { $m=$this->packet($f,$i*5); $m['vehicle']['speed_kmh']=60; $this->assertSame('stored',$this->receive($f,$m)); }
        $this->artisan('telemetry:project-pending')->assertSuccessful(); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertDatabaseCount('telemetry_samples',13); $this->assertDatabaseCount('journeys',1); $this->assertDatabaseCount('driver_shifts',0);
        $this->assertDatabaseHas('telemetry_inbox',['boot_id'=>null,'sequence'=>null,'source_format'=>'pi_snapshot_v1']);
        $this->assertDatabaseHas('mileage_speed_observations',['boot_id'=>null,'observed_uptime_ms'=>null,'source_kind'=>'packet_snapshot']);
        $this->assertDatabaseHas('telemetry_samples',['authorized_shift_id'=>null,'latitude'=>3.139,'fuel_rate_source'=>'maf_petrol_estimate']);
        $base="/api/v1/organizations/{$f[0]->id}/vehicles/{$f[2]}";
        $this->actingAs($f[1])->getJson($base.'/mileage')->assertOk()->assertJsonPath('data.accepted_distance_m',1000)->assertJsonPath('data.distance_sources.0','packet_speed');
        $this->getJson($base.'/latest-state')->assertOk()->assertJsonPath('data.sample.network_data.rssi_dbm',-68)->assertJsonPath('data.sample.quality.clock','unreported');
        $this->getJson($base.'/diagnostics')->assertOk()->assertJsonPath('data.scan_completeness','unknown');
        $query='?from='.urlencode($f[5]->toIso8601String()).'&to='.urlencode($f[5]->addMinute()->toIso8601String());
        $response=$this->getJson($base.'/energy'.$query)->assertOk()->assertJsonPath('data.covered_seconds',60);
        $expectedFuel = 12.5*3600/(14.7*745)/60;
        $this->assertEqualsWithDelta($expectedFuel,$response->json('data.fuel_litres'),0.000001);
        $this->assertEqualsWithDelta($expectedFuel*2.31,$response->json('data.estimated_co2_kg'),0.000001);

        // Organization-configured petrol CO2 factor overrides the standard 2.31 default
        $this->patchJson("/api/v1/organizations/{$f[0]->id}/settings",['version'=>1,'settings'=>['co2_petrol_kg_per_litre'=>2.5]])->assertOk();
        $customCo2=$this->getJson($base.'/energy'.$query)->assertOk();
        $this->assertEqualsWithDelta($expectedFuel*2.5,$customCo2->json('data.estimated_co2_kg'),0.000001);
        $this->getJson($base.'/coverage'.$query)->assertOk()->assertJsonCount(12,'data.items');
        $this->getJson("/api/v1/organizations/{$f[0]->id}/dashboard")->assertOk()->assertJsonPath('data.active_automatic_journeys',1);
        $this->getJson("/api/v1/organizations/{$f[0]->id}/fleet-states")->assertOk()->assertJsonCount(1,'data.items');
        $this->assertSame('duplicate',$this->receive($f,$m));
        $m['vehicle']['speed_kmh']=61; $this->assertSame('quarantined',$this->receive($f,$m));
    }
    public function test_invalid_fields_routes_and_historical_installations_are_rejected(): void
    {
        $f=$this->fixture();
        foreach (['gateway.device_id'=>'another-device','gateway.vehicle_id'=>'other-plate','vehicle.speed_kmh'=>'52','network.rssi_dbm'=>99,'timestamp'=>'2026-11-31T12:00:00Z','event_id'=>'','vehicle.dtc_codes'=>['made-up'],'driving_behaviour.harsh_braking'=>1,'unexpected'=>'x'] as $key=>$value) {
            $m=$this->packet($f); data_set($m,$key,$value); $this->assertSame('quarantined',$this->receive($f,$m),$key);
        }
        DB::table('device_installations')->where('id',$f[4])->update(['effective_to'=>$f[5]]);
        $this->assertSame('quarantined',$this->receive($f,$this->packet($f)));
        $this->assertDatabaseCount('telemetry_inbox',0);
    }
    public function test_missing_fuel_and_stale_gps_remain_unknown_and_gap_is_not_bridged(): void
    {
        $f=$this->fixture(); DB::table('vehicles')->where('id',$f[2])->update(['fuel_type'=>'diesel']);
        foreach ([0,5,60] as $seconds) { $m=$this->packet($f,$seconds); $m['vehicle']['speed_kmh']=null; $m['gps']['timestamp']=$f[5]->subMinute()->format('Y-m-d\TH:i:s\Z'); $this->receive($f,$m); }
        $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertDatabaseHas('telemetry_samples',['speed_kmh'=>null,'latitude'=>null,'fuel_rate_lph'=>null]);
        $this->assertDatabaseCount('mileage_speed_observations',0);
        $base="/api/v1/organizations/{$f[0]->id}/vehicles/{$f[2]}";
        $this->actingAs($f[1])->getJson($base.'/energy?from='.urlencode($f[5]->toIso8601String()).'&to='.urlencode($f[5]->addMinute()->toIso8601String()))->assertOk()->assertJsonPath('data.fuel_litres',null)->assertJsonPath('data.unknown_seconds',60);
    }
    public function test_late_pi_packet_splits_energy_and_cannot_replace_latest_state(): void
    {
        $f=$this->fixture(); foreach ([0,10] as $seconds) $this->receive($f,$this->packet($f,$seconds));
        $this->artisan('telemetry:project-pending')->assertSuccessful(); $latest=DB::table('vehicle_latest_states')->value('sample_id');
        $m=$this->packet($f,5); $m['vehicle']['maf_gps']=0; $this->receive($f,$m); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertSame($latest,DB::table('vehicle_latest_states')->value('sample_id'));
        $this->assertSame(2,DB::table('vehicle_energy_segments')->whereNull('superseded_at')->count());
        $this->assertSame(1,DB::table('vehicle_energy_segments')->whereNotNull('superseded_at')->count());
    }
    public function test_drivers_and_other_tenants_cannot_read_operational_data(): void
    {
        $f=$this->fixture(); $other=Organization::create(['name'=>'Other','slug'=>'other']);
        $this->actingAs($f[1])->getJson("/api/v1/organizations/{$other->id}/vehicles/{$f[2]}/latest-state")->assertNotFound();
        Membership::where('organization_id',$f[0]->id)->update(['role'=>'driver']);
        foreach (['latest-state','diagnostics','energy','coverage'] as $route) $this->getJson("/api/v1/organizations/{$f[0]->id}/vehicles/{$f[2]}/$route")->assertForbidden();
    }
    public function test_only_accepted_nfc_authorization_links_a_pi_packet_to_a_shift(): void
    {
        $f=$this->fixture(); $f[5]=CarbonImmutable::now('UTC')->startOfSecond();
        config(['nfc.hmac_key'=>str_repeat('K',40),'nfc.hmac_key_version'=>'1']);
        $driver=(string)Str::uuid(); DB::table('drivers')->insert(['id'=>$driver,'organization_id'=>$f[0]->id,'name'=>'Driver','status'=>'active','created_at'=>now(),'updated_at'=>now()]);
        $this->actingAs($f[1])->postJson("/api/v1/organizations/{$f[0]->id}/drivers/$driver/credentials",['credential_fingerprint'=>str_repeat('a',64),'credential_type'=>'nfc_uid_sha256'])->assertCreated();
        $local=(string)Str::uuid();
        $decision=app(\App\Services\DriverShiftService::class)->acceptAuthenticatedEvent($f[0]->id,$f[3],['event_id'=>(string)Str::uuid(),'installation_id'=>$f[4],'local_session_id'=>$local,'credential_type'=>'nfc_uid_sha256','credential_fingerprint'=>str_repeat('a',64),'occurred_at'=>$f[5]->addSecond()->toIso8601String()]);
        $this->assertSame('accepted',$decision['decision']);
        $m=$this->packet($f,2); $m['driver_session']['session_id']=$local; $m['driver_session']['driver_id']='forged-driver-label';
        $this->receive($f,$m); $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertDatabaseHas('telemetry_samples',['authorized_shift_id'=>$decision['shift_id']]);
        $this->assertDatabaseHas('driver_shifts',['id'=>$decision['shift_id'],'driver_id'=>$driver]);
    }
    public function test_energy_integrates_clipped_boundaries_and_respects_missing_intervals(): void
    {
        $f=$this->fixture();
        foreach ([0=>0,10=>72,30=>72] as $seconds=>$rate) { $m=$this->packet($f,$seconds); $m['vehicle']['fuel_rate_lph']=$rate; $this->receive($f,$m); }
        $this->artisan('telemetry:project-pending')->assertSuccessful();
        $base="/api/v1/organizations/{$f[0]->id}/vehicles/{$f[2]}/energy";
        $r=$this->actingAs($f[1])->getJson($base.'?from='.urlencode($f[5]->addSeconds(5)->toIso8601String()).'&to='.urlencode($f[5]->addSeconds(30)->toIso8601String()))->assertOk();
        $this->assertEqualsWithDelta(0.075,$r->json('data.fuel_litres'),0.000001);
        $r->assertJsonPath('data.covered_seconds',5)->assertJsonPath('data.unknown_seconds',20);
        $this->getJson($base)->assertUnprocessable();
        $this->getJson($base.'?from='.urlencode($f[5]->toIso8601String()).'&to='.urlencode($f[5]->addDays(2)->toIso8601String()))->assertUnprocessable();
    }
    public function test_millisecond_pi_timestamps_keep_gps_when_inbox_occurred_at_is_whole_seconds(): void
    {
        $f=$this->fixture();
        $m=$this->packet($f,0);
        $stamp=$f[5]->format('Y-m-d\TH:i:s').'.250Z';
        $m['timestamp']=$stamp;
        $m['gps']['timestamp']=$stamp;
        $this->assertSame('stored',$this->receive($f,$m));
        // Simulate PostgreSQL TIMESTAMP(0) truncation on telemetry_inbox.occurred_at
        DB::table('telemetry_inbox')->update(['occurred_at'=>$f[5]->format('Y-m-d H:i:s')]);
        $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertDatabaseHas('telemetry_samples',['latitude'=>3.139,'longitude'=>101.6869]);
    }

    public function test_pi_http_driver_auth_splits_unattributed_journey_and_continues_cleanly_without_rebuild(): void
    {
        $f = $this->fixture();
        $f[5] = CarbonImmutable::now('UTC')->subSeconds(40)->startOfSecond();
        config(['nfc.hmac_key' => str_repeat('K', 40), 'nfc.hmac_key_version' => '1']);
        $token = str_repeat('t', 64);
        DB::table('devices')->where('id', $f[3])->update(['auth_token_encrypted' => Crypt::encryptString($token)]);
        $driver = (string) Str::uuid();
        DB::table('drivers')->insert(['id' => $driver, 'organization_id' => $f[0]->id, 'name' => 'Driver', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($f[1])->postJson("/api/v1/organizations/{$f[0]->id}/drivers/$driver/credentials", [
            'credential_fingerprint' => str_repeat('a', 64),
            'credential_type' => 'nfc_uid_sha256',
        ])->assertCreated();
        DB::table('driver_credentials')->where('driver_id', $driver)->update(['issued_at' => $f[5]->subMinutes(1)]);

        foreach ([0, 5, 10, 15] as $seconds) {
            $m = $this->packet($f, $seconds);
            $m['vehicle']['speed_kmh'] = 60;
            $m['driver_session']['session_id'] = null;
            $m['driver_session']['status'] = 'inactive';
            $this->assertSame('stored', $this->receive($f, $m));
        }
        $this->artisan('telemetry:project-pending')->assertSuccessful();
        $autoJourney = DB::table('journeys')->first();
        $this->assertNotNull($autoJourney);
        $this->assertSame('active', $autoJourney->state);
        $this->assertSame('packet-snapshot-v1', $autoJourney->detection_version);

        $local = (string) Str::uuid();
        $authResponse = $this->postJson("/api/v1/device/{$f[3]}/driver-auth", [
            'event_id' => (string) Str::uuid(),
            'local_session_id' => $local,
            'credential_type' => 'nfc_uid_sha256',
            'credential_fingerprint' => str_repeat('a', 64),
            'occurred_at' => $f[5]->addSeconds(20)->toIso8601String(),
        ], ['Authorization' => 'Bearer '.$token])->assertOk();
        $authResponse->assertJsonPath('data.decision', 'accepted')
            ->assertJsonPath('data.driver_id', $driver)
            ->assertJsonPath('data.driver_display_name', 'Driver');
        $shiftId = $authResponse->json('data.shift_id');
        $newJourneyId = $authResponse->json('data.active_journey_id');
        $this->assertNotNull($shiftId);
        $this->assertNotNull($newJourneyId);
        $this->assertNotSame($autoJourney->id, $newJourneyId);

        $this->assertDatabaseHas('journeys', [
            'id' => $autoJourney->id,
            'state' => 'completed',
            'end_reason' => 'driver_authenticated',
        ]);
        $this->assertDatabaseHas('journeys', [
            'id' => $newJourneyId,
            'state' => 'active',
            'detection_version' => 'driver-session-v1',
        ]);
        $jState = DB::table('journey_detection_states')->where('organization_id', $f[0]->id)->where('vehicle_id', $f[2])->first();
        $this->assertSame($newJourneyId, $jState->current_journey_id);
        $this->assertTrue($f[5]->addSeconds(15)->equalTo(CarbonImmutable::parse($jState->last_observed_at)->utc()));

        foreach ([25, 30] as $seconds) {
            $m = $this->packet($f, $seconds);
            $m['vehicle']['speed_kmh'] = 60;
            $m['driver_session']['session_id'] = $local;
            $m['driver_session']['status'] = 'active';
            $this->assertSame('stored', $this->receive($f, $m));
        }
        $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertDatabaseMissing('telemetry_inbox', ['state' => 'projected_requires_rebuild']);
        $this->assertDatabaseMissing('journey_processed_observations', ['outcome' => 'requires_rebuild']);
        $this->assertDatabaseCount('journeys', 2);
        $continued = DB::table('journeys')->where('id', $newJourneyId)->first();
        $this->assertSame('active', $continued->state);
        $this->assertTrue($f[5]->addSeconds(30)->equalTo(CarbonImmutable::parse($continued->last_observed_at)->utc()));
        $this->assertSame(2, DB::table('telemetry_samples')->where('authorized_shift_id', $shiftId)->count());

        $base = "/api/v1/organizations/{$f[0]->id}";
        $this->actingAs($f[1])->getJson($base.'/fleet-states')->assertOk()
            ->assertJsonPath('data.items.0.current_driver_id', $driver)
            ->assertJsonPath('data.items.0.current_driver_name', 'Driver');
        $this->getJson($base.'/vehicles')->assertOk()
            ->assertJsonPath('data.items.0.current_driver_name', 'Driver');
        $this->getJson($base.'/vehicles/'.$f[2])->assertOk()
            ->assertJsonPath('data.item.current_driver_name', 'Driver')
            ->assertJsonPath('data.active_shift.driver_id', $driver);
        $this->getJson($base.'/drivers')->assertOk()
            ->assertJsonPath('data.items.0.current_vehicle_registration', 'VEH-001');
        $this->getJson($base.'/drivers/'.$driver)->assertOk()
            ->assertJsonPath('data.item.current_vehicle_registration', 'VEH-001')
            ->assertJsonPath('data.active_shift.vehicle_id', $f[2]);

        $this->postJson($base."/driver-shifts/{$shiftId}/close", ['reason' => 'Shift complete'])->assertOk();
        $this->getJson($base.'/fleet-states')->assertOk()
            ->assertJsonPath('data.items.0.current_driver_id', null)
            ->assertJsonPath('data.items.0.current_driver_name', null);
        $this->getJson($base.'/vehicles')->assertOk()
            ->assertJsonPath('data.items.0.current_driver_name', null);
        $this->getJson($base.'/vehicles/'.$f[2])->assertOk()
            ->assertJsonPath('data.item.current_driver_name', null)
            ->assertJsonPath('data.active_shift', null);
        $this->getJson($base.'/drivers')->assertOk()
            ->assertJsonPath('data.items.0.current_vehicle_registration', null);
        $this->getJson($base.'/drivers/'.$driver)->assertOk()
            ->assertJsonPath('data.item.current_vehicle_registration', null)
            ->assertJsonPath('data.active_shift', null);
    }

    public function test_pi_telemetry_and_safety_observations_attribute_to_active_server_side_shift_without_packet_driver_session(): void
    {
        $f = $this->fixture();
        $f[5] = CarbonImmutable::now('UTC')->subSeconds(30)->startOfSecond();
        config(['nfc.hmac_key' => str_repeat('K', 40), 'nfc.hmac_key_version' => '1']);
        $driver = (string) Str::uuid();
        DB::table('drivers')->insert(['id' => $driver, 'organization_id' => $f[0]->id, 'name' => 'Driver', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($f[1])->postJson("/api/v1/organizations/{$f[0]->id}/drivers/$driver/credentials", [
            'credential_fingerprint' => str_repeat('a', 64),
            'credential_type' => 'nfc_uid_sha256',
        ])->assertCreated();
        DB::table('driver_credentials')->where('driver_id', $driver)->update(['issued_at' => $f[5]->subMinutes(1)]);

        $decision = app(\App\Services\DriverShiftService::class)->acceptAuthenticatedEvent($f[0]->id, $f[3], [
            'event_id' => (string) Str::uuid(),
            'installation_id' => $f[4],
            'local_session_id' => (string) Str::uuid(),
            'credential_type' => 'nfc_uid_sha256',
            'credential_fingerprint' => str_repeat('a', 64),
            'occurred_at' => $f[5]->toIso8601String(),
        ]);
        $this->assertSame('accepted', $decision['decision']);
        $shiftId = $decision['shift_id'];

        foreach ([5 => 60, 10 => 0] as $seconds => $speed) {
            $m = $this->packet($f, $seconds);
            unset($m['driver_session']);
            $m['vehicle']['speed_kmh'] = $speed;
            $this->assertSame('stored', $this->receive($f, $m));
        }
        $this->artisan('telemetry:project-pending')->assertSuccessful();

        $this->assertSame(2, DB::table('telemetry_samples')->where('authorized_shift_id', $shiftId)->count());
        $this->assertDatabaseHas('safety_events', [
            'vehicle_id' => $f[2],
            'driver_id' => $driver,
            'shift_id' => $shiftId,
            'kind' => 'harsh_braking',
            'state' => 'observed',
        ]);

        $this->actingAs($f[1])->postJson("/api/v1/organizations/{$f[0]->id}/driver-shifts/{$shiftId}/close", ['reason' => 'Shift ended'])->assertOk();
        $afterClose = $this->packet($f, 45);
        unset($afterClose['driver_session']);
        $this->assertSame('stored', $this->receive($f, $afterClose));
        $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertSame(2, DB::table('telemetry_samples')->where('authorized_shift_id', $shiftId)->count());
        $this->assertSame(1, DB::table('telemetry_samples')->whereNull('authorized_shift_id')->count());
    }
}
