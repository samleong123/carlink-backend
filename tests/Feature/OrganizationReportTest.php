<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationReportTest extends TestCase
{
    use RefreshDatabase;

    private function setupOrganization(string $slug = 'test-org', bool $safetyEnabled = true): array
    {
        $user = User::create([
            'email_normalized' => "{$slug}@example.test",
            'display_name' => 'Report Admin',
        ]);

        $org = Organization::create([
            'name' => 'Test Fleet',
            'slug' => $slug,
            'timezone' => 'Asia/Kuala_Lumpur',
        ]);

        $membership = Membership::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $planId = (string) Str::uuid();
        $planSnapshot = [
            'id' => $planId,
            'name' => 'Standard Plan',
            'price_minor' => 1000,
            'currency' => 'MYR',
            'period_days' => 30,
            'vehicle_limit' => 20,
            'driver_limit' => 20,
            'retention_days' => 30,
            'cellular_enabled' => true,
            'safety_enabled' => $safetyEnabled,
            'telemetry_min_seconds' => 2,
        ];

        DB::table('subscription_plans')->insert($planSnapshot + ['created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_subscriptions')->insert([
            'organization_id' => $org->id,
            'plan_id' => $planId,
            'plan_snapshot' => json_encode($planSnapshot, JSON_THROW_ON_ERROR),
            'status' => 'active',
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$org, $user, $membership];
    }

    private function insertSample(string $orgId, string $deviceId, string $instId, string $vehicleId, ?string $sampleId = null, array $attrs = []): string
    {
        $sampleId = $sampleId ?? (string) Str::uuid();
        $inboxId = (string) Str::uuid();
        $now = now();
        $occurred = $attrs['occurred_at'] ?? $now;
        $received = $attrs['received_at'] ?? $now;

        DB::table('telemetry_inbox')->insert([
            'id' => $inboxId,
            'organization_id' => $orgId,
            'device_id' => $deviceId,
            'installation_id' => $instId,
            'event_id' => (string) Str::uuid(),
            'boot_id' => (string) Str::uuid(),
            'sequence' => random_int(1, 999999),
            'occurred_at' => $occurred,
            'received_at' => $received,
            'payload_sha256' => hash('sha256', $inboxId),
            'raw_payload' => '{}',
            'state' => 'projected',
        ]);

        DB::table('telemetry_samples')->insert(array_merge([
            'id' => $sampleId,
            'organization_id' => $orgId,
            'vehicle_id' => $vehicleId,
            'installation_id' => $instId,
            'inbox_id' => $inboxId,
            'occurred_at' => $occurred,
            'received_at' => $received,
            'speed_kmh' => 50.0,
            'quality' => '{}',
            'created_at' => $now,
        ], $attrs));

        return $sampleId;
    }

    public function test_report_preserves_legacy_top_level_keys_and_returns_full_structure(): void
    {
        [$org, $user] = $this->setupOrganization();

        $v1 = (string) Str::uuid();
        $v2 = (string) Str::uuid();
        DB::table('vehicles')->insert([
            ['id' => $v1, 'organization_id' => $org->id, 'registration_number' => 'WXY1111', 'registration_normalized' => 'WXY1111', 'registration_country' => 'MY', 'status' => 'active', 'fuel_type' => 'petrol', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $v2, 'organization_id' => $org->id, 'registration_number' => 'WXY2222', 'registration_normalized' => 'WXY2222', 'registration_country' => 'MY', 'status' => 'inactive', 'fuel_type' => 'diesel', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $d1 = (string) Str::uuid();
        DB::table('drivers')->insert([
            ['id' => $d1, 'organization_id' => $org->id, 'name' => 'Driver One', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->actingAs($user)->getJson("/api/v1/organizations/{$org->id}/reports/summary");
        $response->assertOk();

        // Legacy top-level keys
        $response->assertJsonStructure([
            'status',
            'title',
            'data' => [
                'as_of',
                'vehicles',
                'drivers',
                'safety_observations',
                'open_alerts',
                'compliance_obligations',
                'fleet_summary' => [
                    'active_vehicles',
                    'inactive_or_maintenance_vehicles',
                    'active_drivers',
                    'inactive_drivers',
                    'active_shifts',
                    'active_journeys',
                    'total_journeys_in_window',
                    'distance_km_30d',
                    'fuel_litres_30d',
                    'estimated_co2_kg_30d',
                ],
                'safety_summary' => [
                    'safety_enabled',
                    'average_safety_score',
                    'scored_drivers',
                    'total_drivers',
                    'min_score',
                    'max_score',
                    'total_observed',
                    'total_excluded',
                    'harsh_braking_count',
                    'rapid_acceleration_count',
                    'pending_appeals',
                    'approved_appeals',
                    'rejected_appeals',
                    'daily_trend',
                    'driver_scores',
                    'recent_harsh_events',
                ],
                'alerts_summary' => [
                    'open',
                    'acknowledged',
                    'resolved',
                    'total',
                    'by_severity',
                    'by_kind',
                ],
                'compliance_summary' => [
                    'total_obligations',
                    'verified_records',
                    'pending_records',
                    'expired_or_overdue',
                ],
                'maintenance_summary' => [
                    'assigned_plans',
                    'total_records',
                    'completed_last_30d',
                ],
                'daily_telemetry',
            ],
        ]);

        $data = $response->json('data');
        $this->assertSame(2, $data['vehicles']);
        $this->assertSame(1, $data['drivers']);
        $this->assertSame(0, $data['safety_observations']);
        $this->assertSame(0, $data['open_alerts']);
        $this->assertSame(0, $data['compliance_obligations']);

        $this->assertSame(1, $data['fleet_summary']['active_vehicles']);
        $this->assertSame(1, $data['fleet_summary']['inactive_or_maintenance_vehicles']);
        $this->assertSame(1, $data['fleet_summary']['active_drivers']);
        $this->assertSame(0, $data['fleet_summary']['inactive_drivers']);
    }

    public function test_fleet_summary_metrics_with_distance_energy_and_emissions(): void
    {
        [$org, $user] = $this->setupOrganization();

        $vehicleId = (string) Str::uuid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $org->id,
            'registration_number' => 'WXY5555',
            'registration_normalized' => 'WXY5555',
            'registration_country' => 'MY',
            'status' => 'active',
            'fuel_type' => 'petrol',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deviceId = (string) Str::uuid();
        DB::table('devices')->insert([
            'id' => $deviceId,
            'organization_id' => $org->id,
            'serial_number' => 'DEV-FLEET-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $instId = (string) Str::uuid();
        DB::table('device_installations')->insert([
            'id' => $instId,
            'organization_id' => $org->id,
            'device_id' => $deviceId,
            'vehicle_id' => $vehicleId,
            'effective_from' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $inbox1 = (string) Str::uuid();
        $inbox2 = (string) Str::uuid();
        DB::table('telemetry_inbox')->insert([
            [
                'id' => $inbox1,
                'organization_id' => $org->id,
                'device_id' => $deviceId,
                'installation_id' => $instId,
                'event_id' => (string) Str::uuid(),
                'boot_id' => (string) Str::uuid(),
                'sequence' => 1,
                'occurred_at' => now()->subHours(2),
                'received_at' => now()->subHours(2),
                'payload_sha256' => hash('sha256', 'payload1'),
                'raw_payload' => '{}',
                'state' => 'projected',
            ],
            [
                'id' => $inbox2,
                'organization_id' => $org->id,
                'device_id' => $deviceId,
                'installation_id' => $instId,
                'event_id' => (string) Str::uuid(),
                'boot_id' => (string) Str::uuid(),
                'sequence' => 2,
                'occurred_at' => now()->subHours(1),
                'received_at' => now()->subHours(1),
                'payload_sha256' => hash('sha256', 'payload2'),
                'raw_payload' => '{}',
                'state' => 'projected',
            ],
        ]);

        // Mileage speed observations
        $obs1 = (string) Str::uuid();
        $obs2 = (string) Str::uuid();
        DB::table('mileage_speed_observations')->insert([
            ['id' => $obs1, 'organization_id' => $org->id, 'vehicle_id' => $vehicleId, 'installation_id' => $instId, 'device_id' => $deviceId, 'boot_id' => (string) Str::uuid(), 'sample_id' => (string) Str::uuid(), 'inbox_id' => $inbox1, 'observed_at' => now()->subHours(2), 'observed_uptime_ms' => 1000, 'speed_kmh' => 60.0, 'quality' => 'gps', 'fingerprint' => 'fp1', 'created_at' => now()],
            ['id' => $obs2, 'organization_id' => $org->id, 'vehicle_id' => $vehicleId, 'installation_id' => $instId, 'device_id' => $deviceId, 'boot_id' => (string) Str::uuid(), 'sample_id' => (string) Str::uuid(), 'inbox_id' => $inbox2, 'observed_at' => now()->subHours(1), 'observed_uptime_ms' => 2000, 'speed_kmh' => 60.0, 'quality' => 'gps', 'fingerprint' => 'fp2', 'created_at' => now()],
        ]);

        // Distance segments: one active (15,000 meters = 15 km), one superseded (should be ignored)
        DB::table('vehicle_distance_segments')->insert([
            [
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'vehicle_id' => $vehicleId,
                'start_observation_id' => $obs1,
                'end_observation_id' => $obs2,
                'from_at' => now()->subHours(2),
                'to_at' => now()->subHours(1),
                'distance_m' => 15000.0,
                'source' => 'obd_speed',
                'quality' => 'estimated',
                'algorithm' => 'speed-trapezoid-v1',
                'superseded_at' => null,
                'created_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'vehicle_id' => $vehicleId,
                'start_observation_id' => $obs1,
                'end_observation_id' => $obs2,
                'from_at' => now()->subHours(2),
                'to_at' => now()->subHours(1),
                'distance_m' => 99999.0,
                'source' => 'obd_speed',
                'quality' => 'estimated',
                'algorithm' => 'speed-trapezoid-v1',
                'superseded_at' => now(),
                'created_at' => now(),
            ],
        ]);

        // Telemetry samples & Energy segments: 10 litres of petrol -> 10 * 2.31 = 23.1 kg CO2
        $s1 = $this->insertSample($org->id, $deviceId, $instId, $vehicleId, null, ['occurred_at' => now()->subHours(2)]);
        $s2 = $this->insertSample($org->id, $deviceId, $instId, $vehicleId, null, ['occurred_at' => now()->subHours(1)]);

        DB::table('vehicle_energy_segments')->insert([
            [
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'vehicle_id' => $vehicleId,
                'start_sample_id' => $s1,
                'end_sample_id' => $s2,
                'from_at' => now()->subHours(2),
                'to_at' => now()->subHours(1),
                'litres' => 10.0,
                'source' => 'maf',
                'quality' => 'packet_snapshot_estimate',
                'superseded_at' => null,
                'created_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'vehicle_id' => $vehicleId,
                'start_sample_id' => $s1,
                'end_sample_id' => $s2,
                'from_at' => now()->subHours(2),
                'to_at' => now()->subHours(1),
                'litres' => 50.0,
                'source' => 'maf',
                'quality' => 'packet_snapshot_estimate',
                'superseded_at' => now(),
                'created_at' => now(),
            ],
        ]);

        // Active and completed journeys
        DB::table('journeys')->insert([
            [
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'vehicle_id' => $vehicleId,
                'state' => 'active',
                'started_at' => now()->subMinutes(30),
                'ended_at' => null,
                'last_observed_at' => now(),
                'start_observation_id' => $obs1,
                'end_observation_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'vehicle_id' => $vehicleId,
                'state' => 'completed',
                'started_at' => now()->subHours(3),
                'ended_at' => now()->subHours(2),
                'last_observed_at' => now()->subHours(2),
                'start_observation_id' => $obs1,
                'end_observation_id' => $obs2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $res = $this->actingAs($user)->getJson("/api/v1/organizations/{$org->id}/reports/summary")->assertOk();
        $fleet = $res->json('data.fleet_summary');

        $this->assertEquals(15.0, $fleet['distance_km_30d']);
        $this->assertEquals(10.0, $fleet['fuel_litres_30d']);
        $this->assertEquals(23.1, $fleet['estimated_co2_kg_30d']);
        $this->assertSame(1, $fleet['active_journeys']);
        $this->assertSame(2, $fleet['total_journeys_in_window']);
    }

    public function test_safety_summary_with_events_scores_appeals_and_map_coordinates(): void
    {
        [$org, $user] = $this->setupOrganization();

        $vehicleId = (string) Str::uuid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $org->id,
            'registration_number' => 'WXY8888',
            'registration_normalized' => 'WXY8888',
            'registration_country' => 'MY',
            'status' => 'active',
            'make' => 'Toyota',
            'model' => 'Hilux',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deviceId = (string) Str::uuid();
        DB::table('devices')->insert(['id' => $deviceId, 'organization_id' => $org->id, 'serial_number' => 'DEV-SAFETY-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $instId = (string) Str::uuid();
        DB::table('device_installations')->insert(['id' => $instId, 'organization_id' => $org->id, 'device_id' => $deviceId, 'vehicle_id' => $vehicleId, 'effective_from' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $driverId = (string) Str::uuid();
        DB::table('drivers')->insert([
            'id' => $driverId,
            'organization_id' => $org->id,
            'name' => 'Speedy Driver',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $credId = (string) Str::uuid();
        DB::table('driver_credentials')->insert([
            'id' => $credId,
            'organization_id' => $org->id,
            'driver_id' => $driverId,
            'credential_hmac' => hash('sha256', 'cred'),
            'hash_key_version' => 'v1',
            'type' => 'nfc_mifare',
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shiftId = (string) Str::uuid();
        DB::table('driver_shifts')->insert([
            'id' => $shiftId,
            'organization_id' => $org->id,
            'driver_id' => $driverId,
            'vehicle_id' => $vehicleId,
            'credential_id' => $credId,
            'device_installation_id' => $instId,
            'started_at' => now()->subHours(2),
            'last_authenticated_at' => now()->subHours(2),
            'attribution_expires_at' => now()->addHours(8),
            'state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Telemetry samples with GPS coordinates and speed
        $s1 = $this->insertSample($org->id, $deviceId, $instId, $vehicleId, null, [
            'authorized_shift_id' => $shiftId,
            'occurred_at' => now()->subHours(1)->subSeconds(10),
            'received_at' => now()->subHours(1)->subSeconds(10),
            'speed_kmh' => 80.0,
            'latitude' => 3.1390,
            'longitude' => 101.6869,
        ]);

        $s2 = $this->insertSample($org->id, $deviceId, $instId, $vehicleId, null, [
            'authorized_shift_id' => $shiftId,
            'occurred_at' => now()->subHours(1),
            'received_at' => now()->subHours(1),
            'speed_kmh' => 40.0,
            'latitude' => 3.1395,
            'longitude' => 101.6875,
        ]);

        $s3 = $this->insertSample($org->id, $deviceId, $instId, $vehicleId, null, [
            'authorized_shift_id' => $shiftId,
            'occurred_at' => now()->subMinutes(30),
            'received_at' => now()->subMinutes(30),
            'speed_kmh' => 75.0,
            'latitude' => 3.1400,
            'longitude' => 101.6880,
        ]);

        // Safety events: 1 harsh braking observed, 1 rapid acceleration excluded
        $ev1 = (string) Str::uuid();
        $ev2 = (string) Str::uuid();
        DB::table('safety_events')->insert([
            [
                'id' => $ev1,
                'organization_id' => $org->id,
                'vehicle_id' => $vehicleId,
                'driver_id' => $driverId,
                'shift_id' => $shiftId,
                'start_sample_id' => $s1,
                'end_sample_id' => $s2,
                'kind' => 'harsh_braking',
                'acceleration_ms2' => -4.0,
                'threshold_ms2' => 3.0,
                'occurred_at' => now()->subHours(1),
                'quality' => 'uncalibrated_speed_difference',
                'state' => 'observed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $ev2,
                'organization_id' => $org->id,
                'vehicle_id' => $vehicleId,
                'driver_id' => $driverId,
                'shift_id' => $shiftId,
                'start_sample_id' => $s2,
                'end_sample_id' => $s3,
                'kind' => 'rapid_acceleration',
                'acceleration_ms2' => 3.5,
                'threshold_ms2' => 3.0,
                'occurred_at' => now()->subMinutes(30),
                'quality' => 'uncalibrated_speed_difference',
                'state' => 'excluded',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Appeals: 1 approved for ev2
        DB::table('safety_appeals')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $org->id,
            'event_id' => $ev2,
            'driver_id' => $driverId,
            'submitted_by' => $user->id,
            'reason' => 'Emergency maneuver',
            'status' => 'approved',
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(10),
        ]);

        $res = $this->actingAs($user)->getJson("/api/v1/organizations/{$org->id}/reports/summary")->assertOk();
        $safety = $res->json('data.safety_summary');

        $this->assertTrue($safety['safety_enabled']);
        $this->assertSame(1, $safety['scored_drivers']);
        $this->assertSame(1, $safety['total_drivers']);
        // 1 event included -> score = 100 - 5*1 = 95
        $this->assertEquals(95, $safety['average_safety_score']);
        $this->assertSame(95, $safety['min_score']);
        $this->assertSame(95, $safety['max_score']);

        $this->assertSame(1, $safety['total_observed']);
        $this->assertSame(1, $safety['total_excluded']);
        $this->assertSame(1, $safety['harsh_braking_count']);
        $this->assertSame(1, $safety['rapid_acceleration_count']);
        $this->assertSame(1, $safety['approved_appeals']);
        $this->assertSame(0, $safety['pending_appeals']);

        $this->assertNotEmpty($safety['driver_scores']);
        $this->assertSame('Speedy Driver', $safety['driver_scores'][0]['name']);
        $this->assertSame(95, $safety['driver_scores'][0]['score']);

        $this->assertNotEmpty($safety['recent_harsh_events']);
        $firstEvent = $safety['recent_harsh_events'][0];
        $this->assertSame('WXY8888', $firstEvent['registration_number']);
        $this->assertSame('Speedy Driver', $firstEvent['driver_name']);
        $this->assertNotNull($firstEvent['latitude']);
        $this->assertNotNull($firstEvent['longitude']);
        $this->assertNotNull($firstEvent['delta_speed_kmh']);
    }

    public function test_safety_disabled_in_plan_sets_empty_safety_metrics(): void
    {
        config(['saas.enforce_subscriptions' => true]);
        [$org, $user] = $this->setupOrganization('safety-disabled-org', false);

        $res = $this->actingAs($user)->getJson("/api/v1/organizations/{$org->id}/reports/summary")->assertOk();
        $safety = $res->json('data.safety_summary');

        $this->assertFalse($safety['safety_enabled']);
        $this->assertNull($safety['average_safety_score']);
        $this->assertSame(0, $safety['scored_drivers']);
        $this->assertEmpty($safety['daily_trend']);
        $this->assertEmpty($safety['driver_scores']);
        $this->assertEmpty($safety['recent_harsh_events']);
    }

    public function test_alerts_compliance_and_maintenance_summaries(): void
    {
        [$org, $user] = $this->setupOrganization();

        $vehicleId = (string) Str::uuid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $org->id,
            'registration_number' => 'WXY9999',
            'registration_normalized' => 'WXY9999',
            'registration_country' => 'MY',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Alerts: 1 open warning, 1 resolved critical, 1 superseded (ignored in total)
        DB::table('alert_occurrences')->insert([
            [
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'vehicle_id' => $vehicleId,
                'rule_key' => 'maint.due',
                'rule_version' => 1,
                'episode_key' => 'ep1',
                'threshold_key' => 'th1',
                'kind' => 'maintenance.threshold_crossed',
                'severity' => 'warning',
                'state' => 'open',
                'deduplication_hash' => hash('sha256', 'a1'),
                'occurred_at' => now()->subDay(),
                'detected_at' => now()->subDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'vehicle_id' => $vehicleId,
                'rule_key' => 'compliance.exp',
                'rule_version' => 1,
                'episode_key' => 'ep2',
                'threshold_key' => 'th2',
                'kind' => 'compliance.threshold_crossed',
                'severity' => 'critical',
                'state' => 'resolved',
                'deduplication_hash' => hash('sha256', 'a2'),
                'occurred_at' => now()->subDays(2),
                'detected_at' => now()->subDays(2),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'vehicle_id' => $vehicleId,
                'rule_key' => 'old.rule',
                'rule_version' => 1,
                'episode_key' => 'ep3',
                'threshold_key' => 'th3',
                'kind' => 'maintenance.threshold_crossed',
                'severity' => 'info',
                'state' => 'superseded',
                'deduplication_hash' => hash('sha256', 'a3'),
                'occurred_at' => now()->subDays(3),
                'detected_at' => now()->subDays(3),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Compliance: 1 type, 1 obligation, 1 verified (expired), 1 pending
        $typeId = (string) Str::uuid();
        DB::table('compliance_types')->insert([
            'id' => $typeId,
            'organization_id' => $org->id,
            'code' => 'roadtax',
            'name' => 'Road Tax',
            'subject_type' => 'vehicle',
            'warning_days' => 30,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $obId = (string) Str::uuid();
        DB::table('compliance_obligations')->insert([
            'id' => $obId,
            'organization_id' => $org->id,
            'compliance_type_id' => $typeId,
            'subject_type' => 'vehicle',
            'vehicle_id' => $vehicleId,
            'applicability' => 'applicable',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('compliance_records')->insert([
            [
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'obligation_id' => $obId,
                'cycle_number' => 1,
                'valid_from' => '2025-01-01',
                'expires_on' => '2026-01-01',
                'state' => 'verified',
                'idempotency_hash' => hash('sha256', 'r1'),
                'payload_hash' => hash('sha256', 'p1'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'obligation_id' => $obId,
                'cycle_number' => 2,
                'valid_from' => '2026-01-02',
                'expires_on' => '2027-01-02',
                'state' => 'pending',
                'idempotency_hash' => hash('sha256', 'r2'),
                'payload_hash' => hash('sha256', 'p2'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Maintenance: 1 maintenance plan, 1 assignment, 1 work order, 1 service record completed
        $mPlanId = (string) Str::uuid();
        DB::table('maintenance_plans')->insert([
            'id' => $mPlanId,
            'organization_id' => $org->id,
            'name' => 'Oil Service',
            'service_type' => 'engine_oil',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assignId = (string) Str::uuid();
        DB::table('vehicle_maintenance_plans')->insert([
            'id' => $assignId,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicleId,
            'plan_id' => $mPlanId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $woId = (string) Str::uuid();
        DB::table('maintenance_work_orders')->insert([
            'id' => $woId,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicleId,
            'number' => 'WO-001',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cycleId = (string) Str::uuid();
        DB::table('maintenance_service_cycles')->insert([
            'id' => $cycleId,
            'organization_id' => $org->id,
            'assignment_id' => $assignId,
            'ordinal' => 1,
            'interval_snapshot' => '{}',
            'thresholds_snapshot' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $woItemId = (string) Str::uuid();
        DB::table('maintenance_work_order_items')->insert([
            'id' => $woItemId,
            'organization_id' => $org->id,
            'work_order_id' => $woId,
            'assignment_id' => $assignId,
            'cycle_id' => $cycleId,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('maintenance_service_records')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $org->id,
            'vehicle_id' => $vehicleId,
            'work_order_id' => $woId,
            'work_order_item_id' => $woItemId,
            'performed_at' => now()->subDays(5),
            'odometer_km' => 50000.0,
            'odometer_source' => 'obd',
            'workshop_name' => 'Auto Care',
            'entered_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->actingAs($user)->getJson("/api/v1/organizations/{$org->id}/reports/summary")->assertOk();
        $alerts = $res->json('data.alerts_summary');
        $compliance = $res->json('data.compliance_summary');
        $maintenance = $res->json('data.maintenance_summary');

        // Alerts check
        $this->assertSame(1, $alerts['open']);
        $this->assertSame(0, $alerts['acknowledged']);
        $this->assertSame(1, $alerts['resolved']);
        $this->assertSame(2, $alerts['total']);
        $this->assertSame(1, $alerts['by_severity']['warning']);
        $this->assertSame(1, $alerts['by_severity']['critical']);

        // Compliance check
        $this->assertSame(1, $compliance['total_obligations']);
        $this->assertSame(1, $compliance['verified_records']);
        $this->assertSame(1, $compliance['pending_records']);
        $this->assertSame(1, $compliance['expired_or_overdue']);

        // Maintenance check
        $this->assertSame(1, $maintenance['assigned_plans']);
        $this->assertSame(1, $maintenance['total_records']);
        $this->assertSame(1, $maintenance['completed_last_30d']);
    }

    public function test_daily_telemetry_aggregates_fourteen_days(): void
    {
        [$org, $user] = $this->setupOrganization();

        $vehicleId = (string) Str::uuid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $org->id,
            'registration_number' => 'WXY7777',
            'registration_normalized' => 'WXY7777',
            'registration_country' => 'MY',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deviceId = (string) Str::uuid();
        DB::table('devices')->insert(['id' => $deviceId, 'organization_id' => $org->id, 'serial_number' => 'DEV-DAILY-01', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $instId = (string) Str::uuid();
        DB::table('device_installations')->insert(['id' => $instId, 'organization_id' => $org->id, 'device_id' => $deviceId, 'vehicle_id' => $vehicleId, 'effective_from' => now(), 'created_at' => now(), 'updated_at' => now()]);

        // Insert 3 samples today
        for ($i = 0; $i < 3; $i++) {
            $this->insertSample($org->id, $deviceId, $instId, $vehicleId, null, [
                'occurred_at' => now()->subMinutes(10 * ($i + 1)),
                'received_at' => now()->subMinutes(10 * ($i + 1)),
            ]);
        }

        $res = $this->actingAs($user)->getJson("/api/v1/organizations/{$org->id}/reports/summary")->assertOk();
        $daily = $res->json('data.daily_telemetry');

        $this->assertCount(14, $daily);
        // The last item is today
        $todayEntry = end($daily);
        $this->assertGreaterThanOrEqual(3, $todayEntry['samples']);
    }

    public function test_tenant_isolation_prevents_cross_organization_leak(): void
    {
        [$orgA, $userA] = $this->setupOrganization('org-a');
        [$orgB, $userB] = $this->setupOrganization('org-b');

        // Insert a vehicle in Org B
        DB::table('vehicles')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $orgB->id,
            'registration_number' => 'ORG-B-VEH',
            'registration_normalized' => 'ORGBVEH',
            'registration_country' => 'MY',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Requesting Org A report should NOT see Org B's vehicle
        $resA = $this->actingAs($userA)->getJson("/api/v1/organizations/{$orgA->id}/reports/summary")->assertOk();
        $this->assertSame(0, $resA->json('data.vehicles'));
        $this->assertSame(0, $resA->json('data.fleet_summary.active_vehicles'));

        // Requesting Org B with User A should be 404 (tenant isolation aborts 404)
        $this->actingAs($userA)->getJson("/api/v1/organizations/{$orgB->id}/reports/summary")->assertStatus(404);
    }
}
