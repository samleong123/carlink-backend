<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Crypt, DB, Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class AiInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::purge('logging');
        config(['database.connections.logging' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $this->artisan('migrate', ['--database' => 'logging', '--path' => 'database/migrations/logging', '--force' => true])->assertSuccessful();
    }

    private function createOrgFixture(bool $activeSub = true): array
    {
        config([
            'saas.enforce_subscriptions' => true,
            'dtc_ai.key' => 'private-gemini-key',
            'dtc_ai.audit_model' => 'gemini-3.5-flash-lite',
        ]);

        $owner = User::create([
            'email_normalized' => 'owner-secret@example.test',
            'display_name' => 'Secret Owner Name',
        ]);
        $org = Organization::create([
            'name' => 'Green Fleet Sdn Bhd',
            'slug' => 'green-fleet',
            'contact_email' => 'billing-secret@greenfleet.test',
        ]);
        Membership::create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $planId = (string) Str::uuid();
        $plan = [
            'id' => $planId,
            'name' => 'Pro Fleet',
            'price_minor' => 25000,
            'currency' => 'MYR',
            'period_days' => 30,
            'vehicle_limit' => 20,
            'driver_limit' => 20,
            'retention_days' => 30,
            'cellular_enabled' => true,
            'safety_enabled' => true,
            'telemetry_min_seconds' => 2,
        ];
        DB::table('subscription_plans')->insert($plan + ['created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_subscriptions')->insert([
            'organization_id' => $org->id,
            'plan_id' => $planId,
            'plan_snapshot' => json_encode($plan),
            'status' => $activeSub ? 'active' : 'suspended',
            'expires_at' => $activeSub ? now()->addDays(10) : now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$owner, $org, $planId];
    }

    private function insertSample(
        string $orgId,
        string $deviceId,
        string $instId,
        string $vehicleId,
        CarbonImmutable $occurredAt,
        array $attrs = [],
    ): string {
        $sampleId = (string) Str::uuid();
        $inboxId = (string) Str::uuid();

        DB::table('telemetry_inbox')->insert([
            'id' => $inboxId,
            'organization_id' => $orgId,
            'device_id' => $deviceId,
            'installation_id' => $instId,
            'event_id' => (string) Str::uuid(),
            'boot_id' => null,
            'sequence' => null,
            'source_format' => 'pi_snapshot_v1',
            'occurred_at' => $occurredAt,
            'received_at' => $attrs['received_at'] ?? $occurredAt,
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
            'occurred_at' => $occurredAt,
            'received_at' => $attrs['received_at'] ?? $occurredAt,
            'speed_kmh' => 40.0,
            'engine_rpm' => 1500.0,
            'fuel_rate_lph' => 4.0,
            'fuel_rate_source' => 'maf_petrol_estimate',
            'source_format' => 'pi_snapshot_v1',
            'quality' => '{}',
            'created_at' => now(),
        ], $attrs));

        return $sampleId;
    }

    private function insertMileageObs(string $orgId, string $vehicleId, string $instId, string $deviceId, string $sampleId, string $inboxId, CarbonImmutable $at, float $speed): string
    {
        $id = (string) Str::uuid();
        DB::table('mileage_speed_observations')->insert([
            'id' => $id,
            'organization_id' => $orgId,
            'vehicle_id' => $vehicleId,
            'installation_id' => $instId,
            'device_id' => $deviceId,
            'boot_id' => null,
            'sample_id' => $sampleId,
            'inbox_id' => $inboxId,
            'observed_at' => $at,
            'observed_uptime_ms' => null,
            'speed_kmh' => $speed,
            'quality' => 'valid',
            'fingerprint' => hash('sha256', $id),
            'source_kind' => 'packet_snapshot',
            'created_at' => now(),
        ]);

        return $id;
    }

    public function test_fleet_carbon_ai_enforces_auth_computes_metrics_scrubs_pii_and_parses_markdown_json(): void
    {
        [$owner, $org] = $this->createOrgFixture();

        $driverUser = User::create([
            'email_normalized' => 'driver-pii@example.test',
            'display_name' => 'Ahmad Driver Secret',
        ]);
        Membership::create([
            'organization_id' => $org->id,
            'user_id' => $driverUser->id,
            'role' => 'driver',
            'status' => 'active',
        ]);
        $driverId = (string) Str::uuid();
        DB::table('drivers')->insert([
            'id' => $driverId,
            'organization_id' => $org->id,
            'user_id' => $driverUser->id,
            'name' => 'Ahmad Driver Secret',
            'employee_code' => 'EMP-999-SECRET',
            'email' => 'driver-pii@example.test',
            'phone' => '+60123456789',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherOrg = Organization::create(['name' => 'Other Fleet', 'slug' => 'other-fleet']);

        $vehicleId = (string) Str::uuid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $org->id,
            'registration_number' => 'WXY8888',
            'registration_normalized' => 'WXY8888',
            'registration_country' => 'MY',
            'make' => 'Toyota',
            'model' => 'Hilux',
            'fuel_type' => 'diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deviceId = (string) Str::uuid();
        DB::table('devices')->insert([
            'id' => $deviceId,
            'organization_id' => $org->id,
            'serial_number' => 'DEV-CARB-01',
            'status' => 'active',
            'auth_token_encrypted' => Crypt::encryptString('super-secret-device-token'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $instId = (string) Str::uuid();
        DB::table('device_installations')->insert([
            'id' => $instId,
            'organization_id' => $org->id,
            'device_id' => $deviceId,
            'vehicle_id' => $vehicleId,
            'effective_from' => now()->subDays(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Telemetry samples: four stationary idling samples 10s apart (within 15s maxGap => 30s = 0.5 min idle).
        $t0 = CarbonImmutable::now('UTC')->subHours(2);
        $t1 = $t0->addSeconds(10);
        $t2 = $t0->addSeconds(20);
        $t3 = $t0->addSeconds(30);
        $s1 = $this->insertSample($org->id, $deviceId, $instId, $vehicleId, $t0, ['speed_kmh' => 0.0, 'engine_rpm' => 800.0, 'fuel_rate_lph' => 1.5]);
        $s2 = $this->insertSample($org->id, $deviceId, $instId, $vehicleId, $t1, ['speed_kmh' => 0.5, 'engine_rpm' => 820.0, 'fuel_rate_lph' => 1.5]);
        $this->insertSample($org->id, $deviceId, $instId, $vehicleId, $t2, ['speed_kmh' => 0.0, 'engine_rpm' => 810.0, 'fuel_rate_lph' => 1.5]);
        $this->insertSample($org->id, $deviceId, $instId, $vehicleId, $t3, ['speed_kmh' => 0.0, 'engine_rpm' => 800.0, 'fuel_rate_lph' => 1.5]);

        $inbox1 = DB::table('telemetry_samples')->where('id', $s1)->value('inbox_id');
        $inbox2 = DB::table('telemetry_samples')->where('id', $s2)->value('inbox_id');
        $obs1 = $this->insertMileageObs($org->id, $vehicleId, $instId, $deviceId, $s1, $inbox1, $t0, 0.0);
        $obs2 = $this->insertMileageObs($org->id, $vehicleId, $instId, $deviceId, $s2, $inbox2, $t1, 0.5);

        // Energy & distance segments in window.
        DB::table('vehicle_energy_segments')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $org->id,
            'vehicle_id' => $vehicleId,
            'start_sample_id' => $s1,
            'end_sample_id' => $s2,
            'from_at' => $t0,
            'to_at' => $t1,
            'litres' => 10.0,
            'source' => 'maf_diesel_estimate',
            'quality' => 'packet_snapshot_estimate',
            'created_at' => now(),
        ]);

        DB::table('vehicle_distance_segments')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $org->id,
            'vehicle_id' => $vehicleId,
            'start_observation_id' => $obs1,
            'end_observation_id' => $obs2,
            'from_at' => $t0,
            'to_at' => $t1,
            'distance_m' => 50000.0,
            'source' => 'packet_speed',
            'quality' => 'packet_snapshot_estimate',
            'algorithm' => 'trapezoid_v1',
            'created_at' => now(),
        ]);

        // Overdue maintenance plan (serviced 40 days ago with 30-day calendar interval).
        $planId = (string) Str::uuid();
        DB::table('maintenance_plans')->insert([
            'id' => $planId,
            'organization_id' => $org->id,
            'name' => 'Engine Oil Service',
            'service_type' => 'preventive',
            'status' => 'active',
            'current_revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $assignId = (string) Str::uuid();
        DB::table('vehicle_maintenance_plans')->insert([
            'id' => $assignId,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicleId,
            'plan_id' => $planId,
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('maintenance_service_cycles')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $org->id,
            'assignment_id' => $assignId,
            'ordinal' => 1,
            'serviced_at' => now()->subDays(40),
            'ended_at' => null,
            'interval_snapshot' => json_encode(['calendar_days' => 30]),
            'thresholds_snapshot' => json_encode([]),
            'baseline_quality' => 'unknown_baseline',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::preventStrayRequests();

        // Auth & permission & cross-tenant & validation checks.
        $this->postJson("/api/v1/organizations/{$org->id}/fleet-carbon-ai", [])->assertUnauthorized();
        $this->actingAs($driverUser)->postJson("/api/v1/organizations/{$org->id}/fleet-carbon-ai", [])->assertForbidden();
        $this->actingAs($owner)->postJson("/api/v1/organizations/{$otherOrg->id}/fleet-carbon-ai", [])->assertNotFound();
        $this->actingAs($owner)->postJson("/api/v1/organizations/{$org->id}/fleet-carbon-ai", ['days' => 91])->assertUnprocessable();
        $this->actingAs($owner)->postJson("/api/v1/organizations/{$org->id}/fleet-carbon-ai", ['days' => 0])->assertUnprocessable();
        Http::assertNothingSent();

        $aiJson = json_encode([
            'executive_summary' => 'Fleet diesel consumption is concentrated in WXY8888 with an overdue oil service.',
            'estimated_avoidable_waste' => [
                'fuel_litres' => 1.5,
                'co2_kg' => 4.02,
                'rationale' => 'Reducing stationary idling and resolving overdue service reduces excess burn.',
            ],
            'outliers' => [[
                'vehicle_label' => 'WXY8888 (Toyota Hilux)',
                'issue' => 'Overdue preventive maintenance and stationary idling',
                'fuel_litres' => 10.0,
                'co2_kg' => 26.8,
                'recommended_action' => 'Schedule overdue Engine Oil Service immediately.',
            ]],
            'recommendations' => [[
                'priority' => 'high',
                'title' => 'Clear overdue maintenance on WXY8888',
                'detail' => 'Overdue oil service can degrade fuel economy.',
                'expected_impact' => '3-5% fuel savings',
            ]],
            'esg_highlights' => ['100% diesel fleet currently tracked for tank-to-wheel CO2.'],
        ]);

        $fakeMode = 'ok';
        Http::fake(function () use (&$fakeMode, $aiJson) {
            if ($fakeMode === 'error_500') {
                return Http::response(['error' => ['message' => 'upstream error']], 500);
            }
            if ($fakeMode === 'non_json') {
                return Http::response([
                    'candidates' => [['content' => ['parts' => [['text' => 'not json at all']]]]],
                ]);
            }
            return Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => 'thinking...', 'thought' => true],
                            ['text' => "```json\n{$aiJson}\n```"],
                        ],
                    ],
                ]],
            ]);
        });

        $res = $this->actingAs($owner)
            ->postJson("/api/v1/organizations/{$org->id}/fleet-carbon-ai", ['days' => 30])
            ->assertOk()
            ->assertJsonPath('data.model', 'gemini-3.5-flash-lite')
            ->assertJsonPath('data.period_days', 30)
            ->assertJsonPath('data.advisory_only', true)
            ->assertJsonPath('data.metrics_summary.total_vehicles', 1)
            ->assertJsonPath('data.metrics_summary.total_distance_km', 50)
            ->assertJsonPath('data.metrics_summary.total_fuel_litres', 10)
            ->assertJsonPath('data.metrics_summary.total_co2_kg', 26.8)
            ->assertJsonPath('data.metrics_summary.total_idle_minutes', 0.5)
            ->assertJsonPath('data.metrics_summary.overdue_maintenance_count', 1)
            ->assertJsonPath('data.outliers.0.vehicle_label', 'WXY8888 (Toyota Hilux)')
            ->assertJsonPath('data.recommendations.0.priority', 'high');

        $this->assertStringContainsString('WXY8888', $res->json('data.executive_summary'));

        Http::assertSent(function ($request) {
            $promptText = $request['contents'][0]['parts'][0]['text'] ?? '';
            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent'
                && $request->hasHeader('x-goog-api-key', 'private-gemini-key')
                && !str_contains($request->url(), 'key=')
                && ($request['generationConfig']['thinkingConfig']['thinkingLevel'] ?? null) === 'LOW'
                && !str_contains($promptText, 'driver-pii@example.test')
                && !str_contains($promptText, 'owner-secret@example.test')
                && !str_contains($promptText, 'billing-secret@greenfleet.test')
                && !str_contains($promptText, 'Ahmad Driver Secret')
                && !str_contains($promptText, '+60123456789')
                && !str_contains($promptText, 'EMP-999-SECRET')
                && !str_contains($promptText, 'super-secret-device-token');
        });

        // 502 on Gemini HTTP failure.
        $fakeMode = 'error_500';
        $this->actingAs($owner)
            ->postJson("/api/v1/organizations/{$org->id}/fleet-carbon-ai", [])
            ->assertStatus(502);

        // 502 on non-JSON Gemini text.
        $fakeMode = 'non_json';
        $this->actingAs($owner)
            ->postJson("/api/v1/organizations/{$org->id}/fleet-carbon-ai", [])
            ->assertStatus(502);

        // 503 when GEMINI_API_KEY is missing.
        config(['dtc_ai.key' => '']);
        $this->actingAs($owner)
            ->postJson("/api/v1/organizations/{$org->id}/fleet-carbon-ai", [])
            ->assertStatus(503);
    }

    public function test_fleet_carbon_ai_handles_empty_dataset_gracefully(): void
    {
        [$owner, $org] = $this->createOrgFixture();

        Http::fake(fn () => Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode([
                            'executive_summary' => 'No vehicles or telemetry activity were recorded in this 30-day window.',
                            'estimated_avoidable_waste' => ['fuel_litres' => 0, 'co2_kg' => 0, 'rationale' => 'No telemetry recorded.'],
                            'outliers' => [],
                            'recommendations' => [[
                                'priority' => 'low',
                                'title' => 'Onboard fleet vehicles',
                                'detail' => 'Register vehicles and activate OBD telemetry devices to start measuring CO2.',
                                'expected_impact' => 'Baseline visibility',
                            ]],
                            'esg_highlights' => ['No emissions observed in this window.'],
                        ]),
                    ]],
                ],
            ]],
        ]));

        $this->actingAs($owner)
            ->postJson("/api/v1/organizations/{$org->id}/fleet-carbon-ai", [])
            ->assertOk()
            ->assertJsonPath('data.metrics_summary.total_vehicles', 0)
            ->assertJsonPath('data.metrics_summary.total_distance_km', 0)
            ->assertJsonPath('data.metrics_summary.total_fuel_litres', 0)
            ->assertJsonPath('data.metrics_summary.total_co2_kg', 0);
    }

    public function test_fleet_carbon_ai_requires_active_subscription_when_enforced(): void
    {
        [$owner, $org] = $this->createOrgFixture(activeSub: false);
        Http::preventStrayRequests();

        $this->actingAs($owner)
            ->postJson("/api/v1/organizations/{$org->id}/fleet-carbon-ai", [])
            ->assertStatus(402);

        Http::assertNothingSent();
    }

    public function test_driver_eco_coach_ai_is_strictly_driver_scoped_scrubs_pii_and_supports_400_thinking_fallback(): void
    {
        [$owner, $org] = $this->createOrgFixture();

        $driver1User = User::create([
            'email_normalized' => 'driver1-secret@example.test',
            'display_name' => 'Zul Eco Driver',
        ]);
        Membership::create([
            'organization_id' => $org->id,
            'user_id' => $driver1User->id,
            'role' => 'driver',
            'status' => 'active',
        ]);
        $driver1Id = (string) Str::uuid();
        DB::table('drivers')->insert([
            'id' => $driver1Id,
            'organization_id' => $org->id,
            'user_id' => $driver1User->id,
            'name' => 'Zul Eco Driver',
            'employee_code' => 'DRV-LIC-001',
            'email' => 'driver1-secret@example.test',
            'phone' => '+60198887777',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Second driver in same org driving the same vehicle earlier (must NEVER bleed into driver 1!).
        $driver2User = User::create([
            'email_normalized' => 'other-driver-secret@example.test',
            'display_name' => 'Other Driver Secret',
        ]);
        Membership::create([
            'organization_id' => $org->id,
            'user_id' => $driver2User->id,
            'role' => 'driver',
            'status' => 'active',
        ]);
        $driver2Id = (string) Str::uuid();
        DB::table('drivers')->insert([
            'id' => $driver2Id,
            'organization_id' => $org->id,
            'user_id' => $driver2User->id,
            'name' => 'Other Driver Secret',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vehicleId = (string) Str::uuid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $org->id,
            'registration_number' => 'SECRETPLATE99',
            'registration_normalized' => 'SECRETPLATE99',
            'registration_country' => 'MY',
            'fuel_type' => 'petrol',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $deviceId = (string) Str::uuid();
        DB::table('devices')->insert([
            'id' => $deviceId,
            'organization_id' => $org->id,
            'serial_number' => 'DEV-ECO-01',
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
            'effective_from' => now()->subDays(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cred1 = (string) Str::uuid();
        $cred2 = (string) Str::uuid();
        DB::table('driver_credentials')->insert([
            [
                'id' => $cred1,
                'organization_id' => $org->id,
                'driver_id' => $driver1Id,
                'credential_hmac' => hash('sha256', 'c1'),
                'hash_key_version' => '1',
                'type' => 'nfc_uid_sha256',
                'issued_at' => now()->subDays(5),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $cred2,
                'organization_id' => $org->id,
                'driver_id' => $driver2Id,
                'credential_hmac' => hash('sha256', 'c2'),
                'hash_key_version' => '1',
                'type' => 'nfc_uid_sha256',
                'issued_at' => now()->subDays(5),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $t0 = CarbonImmutable::now('UTC')->subHours(3);
        $shift1Id = (string) Str::uuid();
        $shift2Id = (string) Str::uuid();
        DB::table('driver_shifts')->insert([
            [
                'id' => $shift1Id,
                'organization_id' => $org->id,
                'driver_id' => $driver1Id,
                'vehicle_id' => $vehicleId,
                'credential_id' => $cred1,
                'device_installation_id' => $instId,
                'started_at' => $t0,
                'ended_at' => $t0->addMinutes(10),
                'last_authenticated_at' => $t0,
                'attribution_expires_at' => $t0->addHours(8),
                'state' => 'closed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $shift2Id,
                'organization_id' => $org->id,
                'driver_id' => $driver2Id,
                'vehicle_id' => $vehicleId,
                'credential_id' => $cred2,
                'device_installation_id' => $instId,
                'started_at' => $t0->subHours(2),
                'ended_at' => $t0->subHours(1),
                'last_authenticated_at' => $t0->subHours(2),
                'attribution_expires_at' => $t0->addHours(6),
                'state' => 'closed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Driver 1: moving intervals (within 15s maxGap: 30s at 72 km/h => 0.6 km), then 30s idle (3x 10s intervals => 0.5 min).
        $s1 = $this->insertSample($org->id, $deviceId, $instId, $vehicleId, $t0, [
            'authorized_shift_id' => $shift1Id,
            'speed_kmh' => 72.0,
            'engine_rpm' => 2200.0,
            'fuel_rate_lph' => 7.2,
        ]);
        $this->insertSample($org->id, $deviceId, $instId, $vehicleId, $t0->addSeconds(15), [
            'authorized_shift_id' => $shift1Id,
            'speed_kmh' => 72.0,
            'engine_rpm' => 2200.0,
            'fuel_rate_lph' => 7.2,
        ]);
        $s2 = $this->insertSample($org->id, $deviceId, $instId, $vehicleId, $t0->addSeconds(30), [
            'authorized_shift_id' => $shift1Id,
            'speed_kmh' => 72.0,
            'engine_rpm' => 2200.0,
            'fuel_rate_lph' => 7.2,
        ]);
        $s3 = $this->insertSample($org->id, $deviceId, $instId, $vehicleId, $t0->addSeconds(35), [
            'authorized_shift_id' => $shift1Id,
            'speed_kmh' => 0.0,
            'engine_rpm' => 800.0,
            'fuel_rate_lph' => 1.2,
        ]);
        $this->insertSample($org->id, $deviceId, $instId, $vehicleId, $t0->addSeconds(45), [
            'authorized_shift_id' => $shift1Id,
            'speed_kmh' => 0.0,
            'engine_rpm' => 800.0,
            'fuel_rate_lph' => 1.2,
        ]);
        $this->insertSample($org->id, $deviceId, $instId, $vehicleId, $t0->addSeconds(55), [
            'authorized_shift_id' => $shift1Id,
            'speed_kmh' => 0.0,
            'engine_rpm' => 800.0,
            'fuel_rate_lph' => 1.2,
        ]);
        $s4 = $this->insertSample($org->id, $deviceId, $instId, $vehicleId, $t0->addSeconds(65), [
            'authorized_shift_id' => $shift1Id,
            'speed_kmh' => 0.0,
            'engine_rpm' => 800.0,
            'fuel_rate_lph' => 1.2,
        ]);

        // Driver 2: huge speed/fuel on the same vehicle (must NOT be counted in Driver 1's snapshot).
        $this->insertSample($org->id, $deviceId, $instId, $vehicleId, $t0->subMinutes(90), [
            'authorized_shift_id' => $shift2Id,
            'speed_kmh' => 120.0,
            'fuel_rate_lph' => 30.0,
        ]);
        $this->insertSample($org->id, $deviceId, $instId, $vehicleId, $t0->subMinutes(90)->addSeconds(30), [
            'authorized_shift_id' => $shift2Id,
            'speed_kmh' => 120.0,
            'fuel_rate_lph' => 30.0,
        ]);

        // One observed harsh braking event for Driver 1 and one for Driver 2.
        DB::table('safety_events')->insert([
            [
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'vehicle_id' => $vehicleId,
                'driver_id' => $driver1Id,
                'shift_id' => $shift1Id,
                'start_sample_id' => $s2,
                'end_sample_id' => $s3,
                'kind' => 'harsh_braking',
                'acceleration_ms2' => -4.0,
                'threshold_ms2' => 3.0,
                'occurred_at' => $t0->addSeconds(35),
                'quality' => 'uncalibrated_speed_difference',
                'state' => 'observed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'vehicle_id' => $vehicleId,
                'driver_id' => $driver2Id,
                'shift_id' => $shift2Id,
                'start_sample_id' => $s1,
                'end_sample_id' => $s2,
                'kind' => 'rapid_acceleration',
                'acceleration_ms2' => 4.2,
                'threshold_ms2' => 3.0,
                'occurred_at' => $t0->subMinutes(90),
                'quality' => 'uncalibrated_speed_difference',
                'state' => 'observed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Http::preventStrayRequests();

        // Owner without a driver profile gets 404.
        $this->actingAs($owner)->postJson("/api/v1/organizations/{$org->id}/driver/me/eco-coach-ai", [])->assertNotFound();
        // Cross-org access gets 404.
        $otherOrg = Organization::create(['name' => 'Other Org', 'slug' => 'other-eco']);
        $this->actingAs($driver1User)->postJson("/api/v1/organizations/{$otherOrg->id}/driver/me/eco-coach-ai", [])->assertNotFound();
        // Invalid days gets 422.
        $this->actingAs($driver1User)->postJson("/api/v1/organizations/{$org->id}/driver/me/eco-coach-ai", ['days' => 95])->assertUnprocessable();
        Http::assertNothingSent();

        // Test HTTP 400 fallback without thinkingConfig -> succeeds on second call.
        $coachJson = json_encode([
            'encouragement_summary' => 'Smooth cruising at 72 km/h, with room to trim brief stationary idling.',
            'potential_savings' => [
                'fuel_litres' => 0.02,
                'co2_kg' => 0.05,
                'note' => 'Switching off during long stops saves fuel.',
            ],
            'coaching_tips' => [[
                'category' => 'braking',
                'title' => 'Anticipate stops early',
                'tip' => 'Coast gently before red lights to avoid harsh braking.',
                'estimated_benefit' => 'Up to 5% fuel reduction',
            ]],
        ]);

        $callCount = 0;
        Http::fake(function () use (&$callCount, $coachJson) {
            $callCount++;
            if ($callCount === 1) {
                return Http::response(['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'thinkingConfig unsupported']], 400);
            }
            return Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => "```json\n{$coachJson}\n```"],
                        ],
                    ],
                ]],
            ]);
        });

        $res = $this->actingAs($driver1User)
            ->postJson("/api/v1/organizations/{$org->id}/driver/me/eco-coach-ai", ['days' => 14])
            ->assertOk()
            ->assertJsonPath('data.model', 'gemini-3.5-flash-lite')
            ->assertJsonPath('data.period_days', 14)
            ->assertJsonPath('data.advisory_only', true)
            ->assertJsonPath('data.efficiency_snapshot.shifts_count', 1)
            ->assertJsonPath('data.efficiency_snapshot.harsh_events_count', 1)
            ->assertJsonPath('data.efficiency_snapshot.safety_score', 95)
            ->assertJsonPath('data.efficiency_snapshot.idle_minutes', 0.5)
            ->assertJsonPath('data.coaching_tips.0.category', 'braking');

        $this->assertGreaterThan(0.5, $res->json('data.efficiency_snapshot.distance_km'));
        $this->assertLessThan(0.8, $res->json('data.efficiency_snapshot.distance_km'));
        $this->assertSame(2, $callCount);

        Http::assertSent(function ($request) {
            $promptText = $request['contents'][0]['parts'][0]['text'] ?? '';
            return !str_contains($promptText, 'driver1-secret@example.test')
                && !str_contains($promptText, 'Zul Eco Driver')
                && !str_contains($promptText, 'DRV-LIC-001')
                && !str_contains($promptText, '+60198887777')
                && !str_contains($promptText, 'Other Driver Secret')
                && !str_contains($promptText, 'SECRETPLATE99');
        });
    }

    public function test_platform_health_triage_ai_enforces_admin_role_scrubs_pii_and_degrades_logging_cleanly(): void
    {
        [$owner, $org, $planId] = $this->createOrgFixture();

        $admin = User::create(['email_normalized' => 'platform-admin@example.test', 'display_name' => 'SaaS Admin Secret']);
        DB::table('saas_admins')->insert([
            'user_id' => $admin->id,
            'role' => 'admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tech = User::create(['email_normalized' => 'platform-tech@example.test', 'display_name' => 'SaaS Tech']);
        DB::table('saas_admins')->insert([
            'user_id' => $tech->id,
            'role' => 'technician',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed 1 offline active device, 1 pending device, 1 unpaid signup, 1 alert occurrence, and elevated HTTP errors.
        $offlineDeviceId = (string) Str::uuid();
        $pendingDeviceId = (string) Str::uuid();
        DB::table('devices')->insert([
            [
                'id' => $offlineDeviceId,
                'organization_id' => $org->id,
                'serial_number' => 'OFFLINE-001',
                'status' => 'active',
                'auth_token_encrypted' => Crypt::encryptString('secret-token-xyz'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $pendingDeviceId,
                'organization_id' => $org->id,
                'serial_number' => 'PENDING-002',
                'status' => 'registered',
                'auth_token_encrypted' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $vehicleId = (string) Str::uuid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $org->id,
            'registration_number' => 'PLAT1234',
            'registration_normalized' => 'PLAT1234',
            'registration_country' => 'MY',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('alert_occurrences')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $org->id,
            'vehicle_id' => $vehicleId,
            'rule_key' => 'maintenance.test',
            'rule_version' => 1,
            'episode_key' => 'ep-1',
            'threshold_key' => 'due',
            'kind' => 'maintenance.threshold_crossed',
            'severity' => 'high',
            'state' => 'open',
            'deduplication_hash' => hash('sha256', 'alert-1'),
            'occurred_at' => now()->subHour(),
            'detected_at' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $signupOrgId = (string) Str::uuid();
        $signupInvoiceId = (string) Str::uuid();
        DB::table('organization_signups')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'organization_id' => $signupOrgId,
            'invoice_id' => $signupInvoiceId,
            'request_key_hash' => hash('sha256', 'rk-1'),
            'request_hash' => hash('sha256', 'rh-1'),
            'status' => 'pending',
            'payment_due_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Elevated 5xx error logs with PII IP address that must NOT be sent to Gemini.
        DB::connection('logging')->table('http_request_logs')->insert([
            [
                'request_id' => (string) Str::uuid(),
                'started_at' => now()->subMinutes(10),
                'completed_at' => now()->subMinutes(10),
                'state' => 'completed',
                'organization_id' => $org->id,
                'user_id' => $owner->id,
                'method' => 'GET',
                'route' => 'api/v1/organizations/{organization}/vehicles',
                'http_status' => 500,
                'duration_ms' => 120,
                'context' => json_encode(['ip_address' => '203.0.113.55', 'email' => 'leak@example.test']),
            ],
        ]);

        Http::preventStrayRequests();

        // RBAC checks.
        $this->postJson('/api/v1/platform/health-triage-ai', [])->assertUnauthorized();
        $this->actingAs($owner)->postJson('/api/v1/platform/health-triage-ai', [])->assertForbidden();
        $this->actingAs($tech)->postJson('/api/v1/platform/health-triage-ai', [])->assertForbidden();
        $this->actingAs($admin)->postJson('/api/v1/platform/health-triage-ai', ['days' => 31])->assertUnprocessable();
        Http::assertNothingSent();

        $triageJson = json_encode([
            'executive_brief' => 'One organization has elevated 5xx errors and an expiring subscription, with one offline device.',
            'priority_queue' => [[
                'severity' => 'critical',
                'area' => 'api_errors',
                'target' => 'green-fleet',
                'reason' => '100% 5xx rate observed in recent window.',
                'next_action' => 'Inspect server error traces for green-fleet endpoints.',
            ]],
        ]);

        $fakeMode = 'ok';
        Http::fake(function () use (&$fakeMode, $triageJson) {
            if ($fakeMode === 'error_503') {
                return Http::response(['error' => ['message' => 'down']], 503);
            }
            return Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => "Here is the triage:\n{$triageJson}",
                        ]],
                    ],
                ]],
            ]);
        });

        $this->actingAs($admin)
            ->postJson('/api/v1/platform/health-triage-ai', ['days' => 7])
            ->assertOk()
            ->assertJsonPath('data.model', 'gemini-3.5-flash-lite')
            ->assertJsonPath('data.period_days', 7)
            ->assertJsonPath('data.advisory_only', true)
            ->assertJsonPath('data.platform_signals.offline_devices', 1)
            ->assertJsonPath('data.platform_signals.pending_devices', 1)
            ->assertJsonPath('data.platform_signals.expiring_subscriptions', 1)
            ->assertJsonPath('data.platform_signals.unpaid_signups', 1)
            ->assertJsonPath('data.platform_signals.elevated_error_orgs', 1)
            ->assertJsonPath('data.platform_signals.recent_alerts', 1)
            ->assertJsonPath('data.priority_queue.0.severity', 'critical')
            ->assertJsonPath('data.priority_queue.0.area', 'api_errors');

        Http::assertSent(function ($request) {
            $promptText = $request['contents'][0]['parts'][0]['text'] ?? '';
            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent'
                && $request->hasHeader('x-goog-api-key', 'private-gemini-key')
                && !str_contains($request->url(), 'key=')
                && !str_contains($promptText, '203.0.113.55')
                && !str_contains($promptText, 'owner-secret@example.test')
                && !str_contains($promptText, 'billing-secret@greenfleet.test')
                && !str_contains($promptText, 'platform-admin@example.test')
                && !str_contains($promptText, 'SaaS Admin Secret')
                && !str_contains($promptText, 'secret-token-xyz')
                && !str_contains($promptText, 'leak@example.test');
        });

        // Verify graceful degradation when logging DB is unavailable (missing table).
        DB::connection('logging')->getSchemaBuilder()->dropIfExists('http_request_logs');
        $this->actingAs($admin)
            ->postJson('/api/v1/platform/health-triage-ai', ['days' => 7])
            ->assertOk()
            ->assertJsonPath('data.platform_signals.elevated_error_orgs', 0);

        // Verify 502 on Gemini provider error and 503 when key missing.
        $fakeMode = 'error_503';
        $this->actingAs($admin)->postJson('/api/v1/platform/health-triage-ai', [])->assertStatus(502);

        config(['dtc_ai.key' => '']);
        $this->actingAs($admin)->postJson('/api/v1/platform/health-triage-ai', [])->assertStatus(503);
    }
}
