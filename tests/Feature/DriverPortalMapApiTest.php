<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DriverPortalMapApiTest extends TestCase
{
    use RefreshDatabase;

    private function setupFixture(bool $safetyEnabled = true): array
    {
        $this->seed(PermissionSeeder::class);

        // Org 1 setup
        $owner = User::create(['email_normalized' => 'owner@example.test', 'display_name' => 'Owner']);
        $org = Organization::create(['name' => 'Fleet Org', 'slug' => 'fleet-org']);
        Membership::create(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);

        $planId = (string) Str::uuid();
        $planTerms = [
            'id' => $planId,
            'name' => 'Fleet Plan',
            'price_minor' => 1000,
            'currency' => 'MYR',
            'period_days' => 30,
            'vehicle_limit' => 5,
            'driver_limit' => 5,
            'retention_days' => 30,
            'cellular_enabled' => true,
            'safety_enabled' => $safetyEnabled,
            'telemetry_min_seconds' => 2,
        ];
        DB::table('subscription_plans')->insert($planTerms + ['created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_subscriptions')->insert([
            'organization_id' => $org->id,
            'plan_id' => $planId,
            'plan_snapshot' => json_encode($planTerms),
            'status' => 'active',
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Driver 1
        $driverUser1 = User::create(['email_normalized' => 'driver1@example.test', 'display_name' => 'Driver One']);
        $membership1 = Membership::create(['organization_id' => $org->id, 'user_id' => $driverUser1->id, 'role' => 'driver', 'status' => 'active']);
        $driver1 = (string) Str::uuid();
        DB::table('drivers')->insert([
            'id' => $driver1,
            'organization_id' => $org->id,
            'user_id' => $driverUser1->id,
            'name' => 'Driver One',
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Driver 2 (in Org 1)
        $driverUser2 = User::create(['email_normalized' => 'driver2@example.test', 'display_name' => 'Driver Two']);
        $membership2 = Membership::create(['organization_id' => $org->id, 'user_id' => $driverUser2->id, 'role' => 'driver', 'status' => 'active']);
        $driver2 = (string) Str::uuid();
        DB::table('drivers')->insert([
            'id' => $driver2,
            'organization_id' => $org->id,
            'user_id' => $driverUser2->id,
            'name' => 'Driver Two',
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Vehicles
        $vehicle1 = (string) Str::uuid();
        DB::table('vehicles')->insert([
            'id' => $vehicle1,
            'organization_id' => $org->id,
            'registration_number' => 'ABC1234',
            'registration_normalized' => 'ABC1234',
            'registration_country' => 'MY',
            'make' => 'Toyota',
            'model' => 'Corolla',
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vehicle2 = (string) Str::uuid();
        DB::table('vehicles')->insert([
            'id' => $vehicle2,
            'organization_id' => $org->id,
            'registration_number' => 'XYZ9876',
            'registration_normalized' => 'XYZ9876',
            'registration_country' => 'MY',
            'make' => 'Honda',
            'model' => 'Civic',
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Devices & Installations
        $device1 = (string) Str::uuid();
        DB::table('devices')->insert(['id' => $device1, 'organization_id' => $org->id, 'serial_number' => 'DEV-001', 'broker_principal' => 'd-001', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $installation1 = (string) Str::uuid();
        DB::table('device_installations')->insert(['id' => $installation1, 'organization_id' => $org->id, 'device_id' => $device1, 'vehicle_id' => $vehicle1, 'effective_from' => now()->subDays(5), 'created_at' => now(), 'updated_at' => now()]);

        $device2 = (string) Str::uuid();
        DB::table('devices')->insert(['id' => $device2, 'organization_id' => $org->id, 'serial_number' => 'DEV-002', 'broker_principal' => 'd-002', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $installation2 = (string) Str::uuid();
        DB::table('device_installations')->insert(['id' => $installation2, 'organization_id' => $org->id, 'device_id' => $device2, 'vehicle_id' => $vehicle2, 'effective_from' => now()->subDays(5), 'created_at' => now(), 'updated_at' => now()]);

        // Credentials
        $cred1 = (string) Str::uuid();
        DB::table('driver_credentials')->insert([
            'id' => $cred1,
            'organization_id' => $org->id,
            'driver_id' => $driver1,
            'credential_hmac' => hash('sha256', 'cred1'),
            'hash_key_version' => '1',
            'type' => 'nfc_uid_sha256',
            'issued_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cred2 = (string) Str::uuid();
        DB::table('driver_credentials')->insert([
            'id' => $cred2,
            'organization_id' => $org->id,
            'driver_id' => $driver2,
            'credential_hmac' => hash('sha256', 'cred2'),
            'hash_key_version' => '1',
            'type' => 'nfc_uid_sha256',
            'issued_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Shifts
        $t = CarbonImmutable::now('UTC')->subHours(2)->startOfMinute();
        $shift1 = (string) Str::uuid();
        DB::table('driver_shifts')->insert([
            'id' => $shift1,
            'organization_id' => $org->id,
            'driver_id' => $driver1,
            'vehicle_id' => $vehicle1,
            'credential_id' => $cred1,
            'device_installation_id' => $installation1,
            'started_at' => $t,
            'ended_at' => $t->addHours(1),
            'last_authenticated_at' => $t,
            'attribution_expires_at' => $t->addHours(1),
            'state' => 'closed',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shift2 = (string) Str::uuid();
        DB::table('driver_shifts')->insert([
            'id' => $shift2,
            'organization_id' => $org->id,
            'driver_id' => $driver2,
            'vehicle_id' => $vehicle2,
            'credential_id' => $cred2,
            'device_installation_id' => $installation2,
            'started_at' => $t,
            'ended_at' => $t->addHours(1),
            'last_authenticated_at' => $t,
            'attribution_expires_at' => $t->addHours(1),
            'state' => 'closed',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Journeys
        $journey1 = (string) Str::uuid();
        DB::table('journeys')->insert([
            'id' => $journey1,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle1,
            'started_at' => $t,
            'last_observed_at' => $t->addMinutes(30),
            'start_observation_id' => (string) Str::uuid(),
            'state' => 'closed',
            'detection_version' => 'packet-snapshot-v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $journey2 = (string) Str::uuid();
        DB::table('journeys')->insert([
            'id' => $journey2,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle2,
            'started_at' => $t,
            'last_observed_at' => $t->addMinutes(30),
            'start_observation_id' => (string) Str::uuid(),
            'state' => 'closed',
            'detection_version' => 'packet-snapshot-v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Telemetry inboxes & samples for Driver 1 / Vehicle 1
        $inbox1 = (string) Str::uuid();
        DB::table('telemetry_inbox')->insert([
            'id' => $inbox1,
            'organization_id' => $org->id,
            'device_id' => $device1,
            'installation_id' => $installation1,
            'event_id' => (string) Str::uuid(),
            'boot_id' => (string) Str::uuid(),
            'sequence' => 1,
            'source_format' => 'canonical_v1',
            'received_at' => $t,
            'occurred_at' => $t,
            'payload_sha256' => str_repeat('0', 64),
            'raw_payload' => '{}',
            'state' => 'projected',
        ]);
        $sample1 = (string) Str::uuid();
        DB::table('telemetry_samples')->insert([
            'id' => $sample1,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle1,
            'installation_id' => $installation1,
            'authorized_shift_id' => $shift1,
            'inbox_id' => $inbox1,
            'occurred_at' => $t,
            'received_at' => $t,
            'speed_kmh' => 60.000,
            'engine_rpm' => 2500.000,
            'latitude' => 3.1390000,
            'longitude' => 101.6869000,
            'fuel_rate_lph' => 4.500000,
            'quality' => json_encode(['clock' => 'ok']),
            'created_at' => now(),
        ]);

        $inbox2 = (string) Str::uuid();
        DB::table('telemetry_inbox')->insert([
            'id' => $inbox2,
            'organization_id' => $org->id,
            'device_id' => $device1,
            'installation_id' => $installation1,
            'event_id' => (string) Str::uuid(),
            'boot_id' => (string) Str::uuid(),
            'sequence' => 2,
            'source_format' => 'canonical_v1',
            'received_at' => $t->addSeconds(5),
            'occurred_at' => $t->addSeconds(5),
            'payload_sha256' => str_repeat('0', 64),
            'raw_payload' => '{}',
            'state' => 'projected',
        ]);
        $sample2 = (string) Str::uuid();
        DB::table('telemetry_samples')->insert([
            'id' => $sample2,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle1,
            'installation_id' => $installation1,
            'authorized_shift_id' => $shift1,
            'inbox_id' => $inbox2,
            'occurred_at' => $t->addSeconds(5),
            'received_at' => $t->addSeconds(5),
            'speed_kmh' => 20.000,
            'engine_rpm' => 1200.000,
            'latitude' => 3.1395000,
            'longitude' => 101.6875000,
            'fuel_rate_lph' => 2.000000,
            'quality' => json_encode(['clock' => 'ok']),
            'created_at' => now(),
        ]);

        $inbox3 = (string) Str::uuid();
        DB::table('telemetry_inbox')->insert([
            'id' => $inbox3,
            'organization_id' => $org->id,
            'device_id' => $device1,
            'installation_id' => $installation1,
            'event_id' => (string) Str::uuid(),
            'boot_id' => (string) Str::uuid(),
            'sequence' => 3,
            'source_format' => 'canonical_v1',
            'received_at' => $t->addSeconds(10),
            'occurred_at' => $t->addSeconds(10),
            'payload_sha256' => str_repeat('0', 64),
            'raw_payload' => '{}',
            'state' => 'projected',
        ]);
        $sample3 = (string) Str::uuid();
        DB::table('telemetry_samples')->insert([
            'id' => $sample3,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle1,
            'installation_id' => $installation1,
            'authorized_shift_id' => $shift1,
            'inbox_id' => $inbox3,
            'occurred_at' => $t->addSeconds(10),
            'received_at' => $t->addSeconds(10),
            'speed_kmh' => 0.000,
            'engine_rpm' => 800.000,
            'latitude' => 3.1400000,
            'longitude' => 101.6880000,
            'fuel_rate_lph' => 0.800000,
            'quality' => json_encode(['clock' => 'ok']),
            'created_at' => now(),
        ]);

        // Safety Event for Driver 1 (harsh braking)
        $safetyEvent1 = (string) Str::uuid();
        DB::table('safety_events')->insert([
            'id' => $safetyEvent1,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle1,
            'driver_id' => $driver1,
            'shift_id' => $shift1,
            'start_sample_id' => $sample1,
            'end_sample_id' => $sample2,
            'kind' => 'harsh_braking',
            'acceleration_ms2' => -4.200000,
            'threshold_ms2' => 3.000000,
            'occurred_at' => $t->addSeconds(5),
            'quality' => 'uncalibrated_speed_difference',
            'state' => 'observed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Telemetry & Safety Event for Driver 2 (separate vehicle and driver)
        $inboxD2_1 = (string) Str::uuid();
        DB::table('telemetry_inbox')->insert([
            'id' => $inboxD2_1,
            'organization_id' => $org->id,
            'device_id' => $device2,
            'installation_id' => $installation2,
            'event_id' => (string) Str::uuid(),
            'boot_id' => (string) Str::uuid(),
            'sequence' => 1,
            'source_format' => 'canonical_v1',
            'received_at' => $t,
            'occurred_at' => $t,
            'payload_sha256' => str_repeat('0', 64),
            'raw_payload' => '{}',
            'state' => 'projected',
        ]);
        $sampleD2_1 = (string) Str::uuid();
        DB::table('telemetry_samples')->insert([
            'id' => $sampleD2_1,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle2,
            'installation_id' => $installation2,
            'authorized_shift_id' => $shift2,
            'inbox_id' => $inboxD2_1,
            'occurred_at' => $t,
            'received_at' => $t,
            'speed_kmh' => 30.000,
            'engine_rpm' => 1500.000,
            'latitude' => 5.4164000,
            'longitude' => 100.3327000,
            'fuel_rate_lph' => 3.000000,
            'quality' => json_encode(['clock' => 'ok']),
            'created_at' => now(),
        ]);

        $inboxD2_2 = (string) Str::uuid();
        DB::table('telemetry_inbox')->insert([
            'id' => $inboxD2_2,
            'organization_id' => $org->id,
            'device_id' => $device2,
            'installation_id' => $installation2,
            'event_id' => (string) Str::uuid(),
            'boot_id' => (string) Str::uuid(),
            'sequence' => 2,
            'source_format' => 'canonical_v1',
            'received_at' => $t->addSeconds(5),
            'occurred_at' => $t->addSeconds(5),
            'payload_sha256' => str_repeat('0', 64),
            'raw_payload' => '{}',
            'state' => 'projected',
        ]);
        $sampleD2_2 = (string) Str::uuid();
        DB::table('telemetry_samples')->insert([
            'id' => $sampleD2_2,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle2,
            'installation_id' => $installation2,
            'authorized_shift_id' => $shift2,
            'inbox_id' => $inboxD2_2,
            'occurred_at' => $t->addSeconds(5),
            'received_at' => $t->addSeconds(5),
            'speed_kmh' => 70.000,
            'engine_rpm' => 3500.000,
            'latitude' => 5.4170000,
            'longitude' => 100.3330000,
            'fuel_rate_lph' => 6.000000,
            'quality' => json_encode(['clock' => 'ok']),
            'created_at' => now(),
        ]);

        $safetyEvent2 = (string) Str::uuid();
        DB::table('safety_events')->insert([
            'id' => $safetyEvent2,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle2,
            'driver_id' => $driver2,
            'shift_id' => $shift2,
            'start_sample_id' => $sampleD2_1,
            'end_sample_id' => $sampleD2_2,
            'kind' => 'rapid_acceleration',
            'acceleration_ms2' => 4.500000,
            'threshold_ms2' => 3.000000,
            'occurred_at' => $t->addSeconds(5),
            'quality' => 'uncalibrated_speed_difference',
            'state' => 'observed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Second Organization (Org 2) for tenant isolation tests
        $org2 = Organization::create(['name' => 'Foreign Org', 'slug' => 'foreign-org']);
        $owner2 = User::create(['email_normalized' => 'owner2@example.test', 'display_name' => 'Owner Two']);
        Membership::create(['organization_id' => $org2->id, 'user_id' => $owner2->id, 'role' => 'owner', 'status' => 'active']);

        $segmentId1 = hash('sha256', $journey1 . $shift1);
        $segmentId2 = hash('sha256', $journey2 . $shift2);

        return [
            'org' => $org,
            'owner' => $owner,
            'driverUser1' => $driverUser1,
            'driver1' => $driver1,
            'driverUser2' => $driverUser2,
            'driver2' => $driver2,
            'vehicle1' => $vehicle1,
            'vehicle2' => $vehicle2,
            'shift1' => $shift1,
            'shift2' => $shift2,
            'journey1' => $journey1,
            'journey2' => $journey2,
            'segmentId1' => $segmentId1,
            'segmentId2' => $segmentId2,
            'safetyEvent1' => $safetyEvent1,
            'safetyEvent2' => $safetyEvent2,
            'sample1' => $sample1,
            'sample2' => $sample2,
            'sample3' => $sample3,
            'org2' => $org2,
            'owner2' => $owner2,
            'time' => $t,
        ];
    }

    public function test_safety_controller_events_returns_enriched_coordinates_delta_speed_and_vehicle(): void
    {
        $f = $this->setupFixture();
        $url = "/api/v1/organizations/{$f['org']->id}/safety-events";

        $res = $this->actingAs($f['owner'])->getJson($url)->assertOk();
        $items = $res->json('data.items');
        $this->assertCount(2, $items);

        $event1 = collect($items)->firstWhere('id', $f['safetyEvent1']);
        $this->assertNotNull($event1);
        $this->assertEquals(3.1395, (float) $event1['latitude']);
        $this->assertEquals(101.6875, (float) $event1['longitude']);
        $this->assertEquals(3.1390, (float) $event1['start_latitude']);
        $this->assertEquals(101.6869, (float) $event1['start_longitude']);
        $this->assertEquals(3.1395, (float) $event1['end_latitude']);
        $this->assertEquals(101.6875, (float) $event1['end_longitude']);
        $this->assertEquals(60.0, (float) $event1['start_speed_kmh']);
        $this->assertEquals(20.0, (float) $event1['end_speed_kmh']);
        $this->assertEquals(-40.0, (float) $event1['delta_speed_kmh']);
        $this->assertSame('ABC1234', $event1['registration_number']);
        $this->assertSame('Toyota', $event1['vehicle_make']);
        $this->assertSame('Corolla', $event1['vehicle_model']);

        // Test driver_id filter
        $resFilter = $this->actingAs($f['owner'])->getJson($url . '?driver_id=' . $f['driver1'])->assertOk();
        $this->assertCount(1, $resFilter->json('data.items'));
        $this->assertSame($f['safetyEvent1'], $resFilter->json('data.items.0.id'));

        // Test vehicle_id filter
        $resVeh = $this->actingAs($f['owner'])->getJson($url . '?vehicle_id=' . $f['vehicle2'])->assertOk();
        $this->assertCount(1, $resVeh->json('data.items'));
        $this->assertSame($f['safetyEvent2'], $resVeh->json('data.items.0.id'));
        $this->assertEquals(40.0, (float) $resVeh->json('data.items.0.delta_speed_kmh')); // 70 - 30 = 40
        $this->assertSame('XYZ9876', $resVeh->json('data.items.0.registration_number'));
        $this->assertSame('Honda', $resVeh->json('data.items.0.vehicle_make'));
        $this->assertSame('Civic', $resVeh->json('data.items.0.vehicle_model'));
    }

    public function test_driver_me_events_enriches_coordinates_delta_speed_and_isolates_other_drivers(): void
    {
        $f = $this->setupFixture();
        $url = "/api/v1/organizations/{$f['org']->id}/driver/me/events";

        $res = $this->actingAs($f['driverUser1'])->getJson($url)->assertOk();
        $items = $res->json('data.items');
        $this->assertCount(1, $items);

        $event = $items[0];
        $this->assertSame($f['safetyEvent1'], $event['id']);
        $this->assertEquals(3.1395, (float) $event['latitude']);
        $this->assertEquals(101.6875, (float) $event['longitude']);
        $this->assertEquals(60.0, (float) $event['start_speed_kmh']);
        $this->assertEquals(20.0, (float) $event['end_speed_kmh']);
        $this->assertEquals(-40.0, (float) $event['delta_speed_kmh']);
        $this->assertSame('ABC1234', $event['registration_number']);
        $this->assertSame('Toyota', $event['vehicle_make']);
        $this->assertSame('Corolla', $event['vehicle_model']);

        // Driver 2 sees only their event
        $resD2 = $this->actingAs($f['driverUser2'])->getJson($url)->assertOk();
        $itemsD2 = $resD2->json('data.items');
        $this->assertCount(1, $itemsD2);
        $this->assertSame($f['safetyEvent2'], $itemsD2[0]['id']);
        $this->assertSame('XYZ9876', $itemsD2[0]['registration_number']);
    }

    public function test_driver_me_records_orders_descending_joins_vehicle_and_supports_filters(): void
    {
        $f = $this->setupFixture();
        $url = "/api/v1/organizations/{$f['org']->id}/driver/me/records";

        $res = $this->actingAs($f['driverUser1'])->getJson($url)->assertOk();
        $items = $res->json('data.items');
        $this->assertCount(3, $items);

        // Verify ordering: occurred_at desc, id desc (Sample 3, Sample 2, Sample 1)
        $this->assertSame($f['sample3'], $items[0]['id']);
        $this->assertSame($f['sample2'], $items[1]['id']);
        $this->assertSame($f['sample1'], $items[2]['id']);

        // Check registration_number
        $this->assertSame('ABC1234', $items[0]['registration_number']);
        $this->assertSame('ABC1234', $items[1]['registration_number']);
        $this->assertSame('ABC1234', $items[2]['registration_number']);

        // Check pagination structure
        $this->assertArrayHasKey('next_cursor', $res->json('data.pagination'));
        $this->assertSame(50, $res->json('data.pagination.per_page'));

        // Test per_page query param (e.g. per_page=2)
        $resPage1 = $this->actingAs($f['driverUser1'])->getJson($url . '?per_page=2')->assertOk();
        $this->assertCount(2, $resPage1->json('data.items'));
        $nextCursor = $resPage1->json('data.pagination.next_cursor');
        $this->assertNotNull($nextCursor);

        // Page 2 using cursor
        $resPage2 = $this->actingAs($f['driverUser1'])->getJson($url . '?per_page=2&cursor=' . urlencode($nextCursor))->assertOk();
        $this->assertCount(1, $resPage2->json('data.items'));
        $this->assertSame($f['sample1'], $resPage2->json('data.items.0.id'));

        // Test filtering: from and to
        $fromTime = urlencode($f['time']->addSeconds(4)->toIso8601String());
        $toTime = urlencode($f['time']->addSeconds(6)->toIso8601String());
        $resFilter = $this->actingAs($f['driverUser1'])->getJson($url . "?from={$fromTime}&to={$toTime}")->assertOk();
        $this->assertCount(1, $resFilter->json('data.items'));
        $this->assertSame($f['sample2'], $resFilter->json('data.items.0.id'));

        // Test filtering by vehicle_id
        $resVeh = $this->actingAs($f['driverUser1'])->getJson($url . '?vehicle_id=' . $f['vehicle1'])->assertOk();
        $this->assertCount(3, $resVeh->json('data.items'));

        $resVehMismatch = $this->actingAs($f['driverUser1'])->getJson($url . '?vehicle_id=' . $f['vehicle2'])->assertOk();
        $this->assertCount(0, $resVehMismatch->json('data.items'));
    }

    public function test_driver_me_trips_includes_vehicle_make_model_and_registration(): void
    {
        $f = $this->setupFixture();
        $url = "/api/v1/organizations/{$f['org']->id}/driver/me/trips";

        $res = $this->actingAs($f['driverUser1'])->getJson($url)->assertOk();
        $items = $res->json('data.items');
        $this->assertCount(1, $items);

        $trip = $items[0];
        $this->assertSame($f['segmentId1'], $trip['segment_id']);
        $this->assertSame($f['vehicle1'], $trip['vehicle_id']);
        $this->assertSame('ABC1234', $trip['registration_number']);
        $this->assertSame('Toyota', $trip['make']);
        $this->assertSame('Corolla', $trip['model']);
        $this->assertSame('own_observed_journey_intersection', $trip['quality']);
        $this->assertFalse($trip['is_fare']);
        $this->assertNotNull($trip['first_own_observation_at']);
        $this->assertNotNull($trip['last_own_observation_at']);
    }

    public function test_driver_me_trip_detail_returns_segment_chronological_samples_and_safety_events(): void
    {
        $f = $this->setupFixture();
        $url = "/api/v1/organizations/{$f['org']->id}/driver/me/trips/{$f['segmentId1']}";

        $res = $this->actingAs($f['driverUser1'])->getJson($url)->assertOk();

        // Check segment details
        $segment = $res->json('data.segment');
        $this->assertSame($f['segmentId1'], $segment['segment_id']);
        $this->assertSame($f['vehicle1'], $segment['vehicle_id']);
        $this->assertSame('ABC1234', $segment['registration_number']);
        $this->assertSame('Toyota', $segment['make']);
        $this->assertSame('Corolla', $segment['model']);
        $this->assertSame(3, $segment['sample_count']);
        $this->assertGreaterThan(0, $segment['distance_km_estimate']);
        $this->assertSame('own_observed_journey_intersection', $segment['quality']);
        $this->assertFalse($segment['is_fare']);

        // Check chronological ordered samples (Sample 1, 2, 3)
        $samples = $res->json('data.samples');
        $this->assertCount(3, $samples);
        $this->assertSame($f['sample1'], $samples[0]['id']);
        $this->assertSame($f['sample2'], $samples[1]['id']);
        $this->assertSame($f['sample3'], $samples[2]['id']);
        $this->assertEquals(60.0, (float) $samples[0]['speed_kmh']);
        $this->assertEquals(20.0, (float) $samples[1]['speed_kmh']);
        $this->assertEquals(0.0, (float) $samples[2]['speed_kmh']);

        // Check enriched safety events
        $safetyEvents = $res->json('data.safety_events');
        $this->assertCount(1, $safetyEvents);
        $ev = $safetyEvents[0];
        $this->assertSame($f['safetyEvent1'], $ev['id']);
        $this->assertEquals(3.1395, (float) $ev['latitude']);
        $this->assertEquals(101.6875, (float) $ev['longitude']);
        $this->assertEquals(-40.0, (float) $ev['delta_speed_kmh']);
        $this->assertSame('ABC1234', $ev['registration_number']);
        $this->assertSame('Toyota', $ev['vehicle_make']);
        $this->assertSame('Corolla', $ev['vehicle_model']);
    }

    public function test_driver_me_trip_detail_when_safety_disabled_returns_empty_safety_events(): void
    {
        $f = $this->setupFixture(safetyEnabled: false);
        config(['saas.enforce_subscriptions' => true]);

        $url = "/api/v1/organizations/{$f['org']->id}/driver/me/trips/{$f['segmentId1']}";
        $res = $this->actingAs($f['driverUser1'])->getJson($url)->assertOk();

        $this->assertSame([], $res->json('data.safety_events'));
        $this->assertCount(3, $res->json('data.samples'));
    }

    public function test_driver_me_trip_detail_validates_segment_and_enforces_driver_isolation(): void
    {
        $f = $this->setupFixture();

        // 1. Invalid segment hex format (not 64 hex characters) -> 404
        $invalidUrl = "/api/v1/organizations/{$f['org']->id}/driver/me/trips/invalid-segment-123";
        $this->actingAs($f['driverUser1'])->getJson($invalidUrl)->assertNotFound();

        // 2. Valid 64 hex format but does not exist -> 404
        $randomHex = str_repeat('a', 64);
        $nonExistentUrl = "/api/v1/organizations/{$f['org']->id}/driver/me/trips/{$randomHex}";
        $this->actingAs($f['driverUser1'])->getJson($nonExistentUrl)->assertNotFound();

        // 3. Driver 1 cannot view Driver 2's segment -> 404
        $driver2SegmentUrl = "/api/v1/organizations/{$f['org']->id}/driver/me/trips/{$f['segmentId2']}";
        $this->actingAs($f['driverUser1'])->getJson($driver2SegmentUrl)->assertNotFound();

        // 4. Driver 2 CAN view their own segment
        $this->actingAs($f['driverUser2'])->getJson($driver2SegmentUrl)->assertOk()
            ->assertJsonPath('data.segment.registration_number', 'XYZ9876');

        // 5. Cross-tenant isolation: Driver 1 cannot access Org 2 (Tenant middleware returns 404 for non-members)
        $crossTenantUrl = "/api/v1/organizations/{$f['org2']->id}/driver/me/trips/{$f['segmentId1']}";
        $this->actingAs($f['driverUser1'])->getJson($crossTenantUrl)->assertNotFound();

        // 6. User without profile.own permission cannot access driver/me
        $noPermUser = User::create(['email_normalized' => 'noperm@example.test', 'display_name' => 'No Perm']);
        Membership::create(['organization_id' => $f['org']->id, 'user_id' => $noPermUser->id, 'role' => 'support', 'status' => 'active']);
        $this->actingAs($noPermUser)->getJson("/api/v1/organizations/{$f['org']->id}/driver/me/trips")->assertForbidden();
        $this->actingAs($noPermUser)->getJson("/api/v1/organizations/{$f['org']->id}/driver/me/trips/{$f['segmentId1']}")->assertForbidden();
    }

    public function test_safety_events_fallback_to_start_sample_and_exclude_superseded(): void
    {
        $f = $this->setupFixture();
        $t = $f['time'];

        // Add sample without latitude/longitude
        $inboxNoGps = (string) Str::uuid();
        DB::table('telemetry_inbox')->insert([
            'id' => $inboxNoGps,
            'organization_id' => $f['org']->id,
            'device_id' => DB::table('devices')->where('organization_id', $f['org']->id)->value('id'),
            'installation_id' => DB::table('device_installations')->where('organization_id', $f['org']->id)->value('id'),
            'event_id' => (string) Str::uuid(),
            'boot_id' => (string) Str::uuid(),
            'sequence' => 10,
            'source_format' => 'canonical_v1',
            'received_at' => $t->addSeconds(15),
            'occurred_at' => $t->addSeconds(15),
            'payload_sha256' => str_repeat('0', 64),
            'raw_payload' => '{}',
            'state' => 'projected',
        ]);
        $sampleEndNoGps = (string) Str::uuid();
        DB::table('telemetry_samples')->insert([
            'id' => $sampleEndNoGps,
            'organization_id' => $f['org']->id,
            'vehicle_id' => $f['vehicle1'],
            'installation_id' => DB::table('device_installations')->where('organization_id', $f['org']->id)->value('id'),
            'authorized_shift_id' => $f['shift1'],
            'inbox_id' => $inboxNoGps,
            'occurred_at' => $t->addSeconds(15),
            'received_at' => $t->addSeconds(15),
            'speed_kmh' => null, // null speed to test null delta_speed_kmh
            'engine_rpm' => 2000.000,
            'latitude' => null, // null gps to test COALESCE
            'longitude' => null,
            'fuel_rate_lph' => 3.000000,
            'quality' => json_encode(['clock' => 'ok']),
            'created_at' => now(),
        ]);

        // Safety event where end sample has null GPS & null speed
        $safetyEventFallback = (string) Str::uuid();
        DB::table('safety_events')->insert([
            'id' => $safetyEventFallback,
            'organization_id' => $f['org']->id,
            'vehicle_id' => $f['vehicle1'],
            'driver_id' => $f['driver1'],
            'shift_id' => $f['shift1'],
            'start_sample_id' => $f['sample1'], // has lat 3.1390, lon 101.6869, speed 60.0
            'end_sample_id' => $sampleEndNoGps, // null lat/lon, null speed
            'kind' => 'harsh_braking',
            'acceleration_ms2' => -3.5,
            'threshold_ms2' => 3.0,
            'occurred_at' => $t->addSeconds(15),
            'quality' => 'uncalibrated_speed_difference',
            'state' => 'observed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Superseded safety event (should be excluded)
        $supersededEvent = (string) Str::uuid();
        DB::table('safety_events')->insert([
            'id' => $supersededEvent,
            'organization_id' => $f['org']->id,
            'vehicle_id' => $f['vehicle1'],
            'driver_id' => $f['driver1'],
            'shift_id' => $f['shift1'],
            'start_sample_id' => $f['sample2'],
            'end_sample_id' => $f['sample3'],
            'kind' => 'harsh_braking',
            'acceleration_ms2' => -5.0,
            'threshold_ms2' => 3.0,
            'occurred_at' => $t->addSeconds(2),
            'quality' => 'uncalibrated_speed_difference',
            'state' => 'superseded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $url = "/api/v1/organizations/{$f['org']->id}/safety-events";
        $res = $this->actingAs($f['owner'])->getJson($url)->assertOk();
        $items = collect($res->json('data.items'));

        // Superseded event must NOT be returned
        $this->assertNull($items->firstWhere('id', $supersededEvent));

        // Fallback event must COALESCE coordinates from start_sample
        $fallback = $items->firstWhere('id', $safetyEventFallback);
        $this->assertNotNull($fallback);
        $this->assertEquals(3.1390, (float) $fallback['latitude']);
        $this->assertEquals(101.6869, (float) $fallback['longitude']);
        $this->assertEquals(3.1390, (float) $fallback['start_latitude']);
        $this->assertEquals(101.6869, (float) $fallback['start_longitude']);
        $this->assertNull($fallback['end_latitude']);
        $this->assertNull($fallback['end_longitude']);
        $this->assertEquals(60.0, (float) $fallback['start_speed_kmh']);
        $this->assertNull($fallback['end_speed_kmh']);
        $this->assertNull($fallback['delta_speed_kmh']); // when either speed is null, delta is null

        // Also test driver/me/events excludes superseded and includes fallback
        $driverEventsRes = $this->actingAs($f['driverUser1'])->getJson("/api/v1/organizations/{$f['org']->id}/driver/me/events")->assertOk();
        $driverItems = collect($driverEventsRes->json('data.items'));
        $this->assertNull($driverItems->firstWhere('id', $supersededEvent));
        $this->assertNotNull($driverItems->firstWhere('id', $safetyEventFallback));
    }
}
