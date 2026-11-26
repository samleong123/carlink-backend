<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Telemetry\TelemetryInbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Crypt, DB, Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class DtcEpisodesAndAiHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::purge('logging');
        config(['database.connections.logging' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $this->artisan('migrate', ['--database' => 'logging', '--path' => 'database/migrations/logging', '--force' => true])->assertSuccessful();
    }

    private function fixture(): array
    {
        config([
            'saas.enforce_subscriptions' => true,
            'broker.verified_topic_acls' => true,
            'dtc_ai.key' => 'private-gemini-key',
            'dtc_ai.model' => 'gemini-3.5-flash-lite',
            'dtc_ai.audit_model' => 'gemini-3.5-flash-lite',
            'mail.default' => 'array',
        ]);

        $owner = User::create(['email_normalized' => 'fleet-owner@example.test', 'display_name' => 'Fleet Owner']);
        $org = Organization::create(['name' => 'DTC Fleet', 'slug' => 'dtc-fleet']);
        Membership::create(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);

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
            'status' => 'active',
            'expires_at' => now()->addDays(15),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vehicleId = (string) Str::uuid();
        $deviceId = (string) Str::uuid();
        $instId = (string) Str::uuid();
        $start = CarbonImmutable::now('UTC')->subMinutes(30)->startOfSecond();

        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $org->id,
            'registration_number' => 'VEH-001',
            'registration_normalized' => 'VEH001',
            'registration_country' => 'MY',
            'make' => 'Proton',
            'model' => 'Saga',
            'fuel_type' => 'petrol',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('devices')->insert([
            'id' => $deviceId,
            'organization_id' => $org->id,
            'serial_number' => 'IOT-001',
            'broker_principal' => $deviceId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('device_installations')->insert([
            'id' => $instId,
            'organization_id' => $org->id,
            'device_id' => $deviceId,
            'vehicle_id' => $vehicleId,
            'effective_from' => $start->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$owner, $org, $vehicleId, $deviceId, $instId, $start];
    }

    private function sendPiPacket(Organization $org, string $deviceId, CarbonImmutable $at, array $dtcCodes, bool $milOn): void
    {
        $payload = json_decode(file_get_contents(base_path('contracts/fixtures/v1/pi_telemetry.json')), true, flags: JSON_THROW_ON_ERROR);
        $payload['event_id'] = (string) Str::uuid();
        $payload['timestamp'] = $at->format('Y-m-d\TH:i:s\Z');
        $payload['gps']['timestamp'] = $payload['timestamp'];
        $payload['vehicle']['mil_on'] = $milOn;
        $payload['vehicle']['has_dtc_fault'] = $milOn || count($dtcCodes) > 0;
        $payload['vehicle']['dtc_codes'] = $dtcCodes;

        $this->assertSame(
            'stored',
            app(TelemetryInbox::class)->receive("fleet.v1.{$org->id}.{$deviceId}.up.telemetry", json_encode($payload, JSON_THROW_ON_ERROR)),
        );
    }

    public function test_dtc_activation_creates_episode_sends_email_and_clears_when_fault_stops(): void
    {
        [$owner, $org, $vehicleId, $deviceId, , $start] = $this->fixture();

        // T+0s & T+10s (< 30s continuous): P0420 stays pending, 0 emails, hidden from dashboard/diagnostics
        $this->sendPiPacket($org, $deviceId, $start, ['P0420'], true);
        $this->sendPiPacket($org, $deviceId, $start->addSeconds(10), ['P0420'], true);
        $this->artisan('telemetry:project-pending')->assertSuccessful();

        $pending = DB::table('vehicle_dtc_episodes')
            ->where('organization_id', $org->id)
            ->where('vehicle_id', $vehicleId)
            ->where('code', 'P0420')
            ->first();

        $this->assertNotNull($pending);
        $this->assertSame('pending', $pending->status);
        $this->assertNull($pending->email_notified_at);
        $this->assertCount(
            0,
            DB::table('email_notifications')
                ->where('organization_id', $org->id)
                ->where('event_key', 'vehicle.dtc_detected')
                ->get(),
        );
        $this->actingAs($owner)
            ->getJson("/api/v1/organizations/{$org->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.active_dtc_episodes', 0)
            ->assertJsonCount(0, 'data.dtc_episodes');

        // T+30s & T+45s (>= 30s continuous): episode becomes active and sends EXACTLY ONE email
        $this->sendPiPacket($org, $deviceId, $start->addSeconds(30), ['P0420'], true);
        $this->sendPiPacket($org, $deviceId, $start->addSeconds(45), ['P0420'], true);
        $this->artisan('telemetry:project-pending')->assertSuccessful();

        $episode = DB::table('vehicle_dtc_episodes')->where('id', $pending->id)->first();
        $this->assertSame('active', $episode->status);
        $this->assertSame(4, (int) $episode->samples_count);
        $this->assertNull($episode->ended_at);
        $this->assertNotNull($episode->email_notified_at);

        $receipts = DB::table('email_notifications')
            ->where('organization_id', $org->id)
            ->where('event_key', 'vehicle.dtc_detected')
            ->get();
        $this->assertCount(1, $receipts);
        $mailOutbox = DB::table('mail_outbox')->where('source_id', $receipts->first()->id)->first();
        $this->assertNotNull($mailOutbox);
        $mailBody = json_decode(Crypt::decryptString($mailOutbox->encrypted_message), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('P0420', $mailBody['body']);
        $this->assertStringContainsString('VEH-001', $mailBody['body']);

        // Dashboard shows confirmed active episode
        $this->actingAs($owner)
            ->getJson("/api/v1/organizations/{$org->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.active_dtc_episodes', 1)
            ->assertJsonPath('data.dtc_episodes.0.code', 'P0420')
            ->assertJsonPath('data.dtc_episodes.0.status', 'active');

        // T+75s (30s gap without code, < 60s disappearance threshold): stays active
        $this->sendPiPacket($org, $deviceId, $start->addSeconds(75), [], false);
        $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertSame('active', DB::table('vehicle_dtc_episodes')->where('id', $episode->id)->value('status'));

        // T+80s: flicker back continues same episode and STILL sends NO second email
        $this->sendPiPacket($org, $deviceId, $start->addSeconds(80), ['P0420'], true);
        $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertCount(
            1,
            DB::table('email_notifications')
                ->where('organization_id', $org->id)
                ->where('event_key', 'vehicle.dtc_detected')
                ->get(),
        );

        // T+140s (>= 60s without code): episode transitions cleanly to cleared
        $this->sendPiPacket($org, $deviceId, $start->addSeconds(140), [], false);
        $this->artisan('telemetry:project-pending')->assertSuccessful();

        $cleared = DB::table('vehicle_dtc_episodes')->where('id', $episode->id)->first();
        $this->assertSame('cleared', $cleared->status);
        $this->assertNotNull($cleared->ended_at);

        $this->actingAs($owner)
            ->getJson("/api/v1/organizations/{$org->id}/vehicles/{$vehicleId}/diagnostics")
            ->assertOk()
            ->assertJsonPath('data.dtc_episodes.0.code', 'P0420')
            ->assertJsonPath('data.dtc_episodes.0.status', 'cleared');

        // Transient < 30s blip (B0001 at T+200s, gone at T+265s) removes pending row with 0 additional emails
        $this->sendPiPacket($org, $deviceId, $start->addSeconds(200), ['B0001'], true);
        $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertDatabaseHas('vehicle_dtc_episodes', ['vehicle_id' => $vehicleId, 'code' => 'B0001', 'status' => 'pending']);

        $this->sendPiPacket($org, $deviceId, $start->addSeconds(265), [], false);
        $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertDatabaseMissing('vehicle_dtc_episodes', ['vehicle_id' => $vehicleId, 'code' => 'B0001']);

        $this->assertCount(
            1,
            DB::table('email_notifications')
                ->where('organization_id', $org->id)
                ->where('event_key', 'vehicle.dtc_detected')
                ->get(),
        );
    }

    public function test_dtc_database_caches_analyzed_code_without_calling_ai_again_and_stores_ai_history(): void
    {
        [$owner, $org, $vehicleId] = $this->fixture();
        $this->actingAs($owner);

        $geminiReply = json_encode([
            'code' => 'P0420',
            'description' => 'Catalyst system efficiency below threshold on bank 1 during normal closed-loop operation.',
            'component' => 'ECU',
            'possible_solution' => 'Inspect upstream and downstream oxygen sensors, check exhaust leaks, and verify catalytic converter thermal efficiency.',
        ]);

        Http::preventStrayRequests();
        $callCount = 0;
        Http::fake(function () use (&$callCount, $geminiReply) {
            $callCount++;
            return Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => $geminiReply]],
                    ],
                ]],
            ]);
        });

        // 1. First tap calls Gemini, saves into dtc_catalog and ai_generations
        $first = $this->postJson("/api/v1/organizations/{$org->id}/dtc-ai", ['code' => 'p0420'])
            ->assertOk()
            ->assertJsonPath('data.code', 'P0420')
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.source', 'gemini_ai')
            ->assertJsonPath('data.lookup_count', 1);

        $this->assertSame(1, $callCount);
        $this->assertDatabaseHas('dtc_catalog', ['code' => 'P0420', 'component' => 'ECU', 'lookup_count' => 1]);
        $this->assertDatabaseCount('ai_generations', 1);

        // 2. Second tap uses DTC database WITHOUT calling Gemini HTTP again
        $this->postJson("/api/v1/organizations/{$org->id}/dtc-ai", ['code' => 'P0420'])
            ->assertOk()
            ->assertJsonPath('data.code', 'P0420')
            ->assertJsonPath('data.cached', true)
            ->assertJsonPath('data.source', 'dtc_database')
            ->assertJsonPath('data.lookup_count', 2);

        $this->assertSame(1, $callCount);

        // 3. Explicit refresh=true calls Gemini and stores a second comparison generation
        $this->postJson("/api/v1/organizations/{$org->id}/dtc-ai", ['code' => 'P0420', 'refresh' => true])
            ->assertOk()
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.source', 'gemini_ai');

        $this->assertSame(2, $callCount);
        $this->assertDatabaseCount('ai_generations', 2);

        // 4. History-only fetch returns stored generations and catalog without hitting AI
        $this->postJson("/api/v1/organizations/{$org->id}/dtc-ai", ['history_only' => true])
            ->assertOk()
            ->assertJsonCount(2, 'data.history')
            ->assertJsonCount(1, 'data.catalog');

        $this->getJson("/api/v1/organizations/{$org->id}/vehicles/{$vehicleId}/diagnostics")
            ->assertOk()
            ->assertJsonCount(1, 'data.dtc_catalog')
            ->assertJsonCount(2, 'data.ai_history');
    }

    public function test_fleet_carbon_ai_stores_all_generations_for_history_and_comparison(): void
    {
        [$owner, $org] = $this->fixture();
        $this->actingAs($owner);

        $aiJson = json_encode([
            'executive_summary' => 'Baseline fleet carbon assessment completed.',
            'estimated_avoidable_waste' => ['fuel_litres' => 1.2, 'co2_kg' => 2.77, 'rationale' => 'Idle trim.'],
            'outliers' => [],
            'recommendations' => [[
                'priority' => 'medium',
                'title' => 'Reduce idle time',
                'detail' => 'Turn off engine at taxi stands.',
                'expected_impact' => '3% savings',
            ]],
            'esg_highlights' => ['Petrol fleet monitored.'],
        ]);

        Http::preventStrayRequests();
        Http::fake(fn () => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $aiJson]]]]],
        ]));

        $this->postJson("/api/v1/organizations/{$org->id}/fleet-carbon-ai", ['days' => 7])->assertOk();
        $this->postJson("/api/v1/organizations/{$org->id}/fleet-carbon-ai", ['days' => 30])
            ->assertOk()
            ->assertJsonCount(2, 'data.history');

        $this->postJson("/api/v1/organizations/{$org->id}/fleet-carbon-ai", ['history_only' => true])
            ->assertOk()
            ->assertJsonCount(2, 'data.history');
    }

    public function test_eco_coach_audit_and_platform_triage_ai_store_history_and_journey_co2_is_reported(): void
    {
        [$owner, $org, $vehicleId, $deviceId, , $start] = $this->fixture();

        $driverUser = User::create(['email_normalized' => 'driver-ai@example.test', 'display_name' => 'Driver AI']);
        Membership::create(['organization_id' => $org->id, 'user_id' => $driverUser->id, 'role' => 'driver', 'status' => 'active']);
        $driverId = (string) Str::uuid();
        DB::table('drivers')->insert([
            'id' => $driverId,
            'organization_id' => $org->id,
            'user_id' => $driverUser->id,
            'name' => 'Driver AI',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::create(['email_normalized' => 'admin-ai@example.test', 'display_name' => 'Platform Admin']);
        DB::table('saas_admins')->insert([
            'user_id' => $admin->id,
            'role' => 'admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $auditId = (string) Str::uuid();
        DB::connection('logging')->table('audit_logs')->insert([
            'id' => $auditId,
            'occurred_at' => now()->subMinutes(5),
            'organization_id' => $org->id,
            'user_id' => $owner->id,
            'action_name' => 'vehicles.create',
            'outcome' => 'succeeded',
            'source' => 'http',
            'request_id' => (string) Str::uuid(),
            'context' => json_encode(['method' => 'POST', 'route' => 'api/v1/organizations/{organization}/vehicles', 'status' => 201]),
        ]);

        Http::preventStrayRequests();
        Http::fake(function ($request) use ($auditId) {
            $prompt = $request['contents'][0]['parts'][0]['text'] ?? '';
            if (str_contains($prompt, 'eco-driving')) {
                $payload = [
                    'encouragement_summary' => 'Steady speed profile maintained.',
                    'potential_savings' => ['fuel_litres' => 0.5, 'co2_kg' => 1.15, 'note' => 'Avoid prolonged idling.'],
                    'coaching_tips' => [['category' => 'idle', 'title' => 'Trim idling', 'tip' => 'Switch off engine on long waits.', 'estimated_benefit' => '2% savings']],
                ];
            } elseif (str_contains($prompt, 'Action Audit')) {
                $payload = [
                    'summary' => 'Owner created a fleet vehicle cleanly.',
                    'items' => [['id' => $auditId, 'action' => 'vehicles.create', 'summary' => 'Registered vehicle', 'intent' => 'Fleet onboarding', 'risk_or_status' => 'Normal · Succeeded']],
                ];
            } else {
                $payload = [
                    'executive_brief' => 'Platform health is nominal.',
                    'priority_queue' => [],
                ];
            }

            return Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($payload)]]]]],
            ]);
        });

        // 1. Driver Eco-Coach AI history
        $this->actingAs($driverUser)->postJson("/api/v1/organizations/{$org->id}/driver/me/eco-coach-ai", ['days' => 14])->assertOk()->assertJsonCount(1, 'data.history');
        $this->actingAs($driverUser)->postJson("/api/v1/organizations/{$org->id}/driver/me/eco-coach-ai", ['history_only' => true])->assertOk()->assertJsonCount(1, 'data.history');

        // 2. Audit AI history
        $this->actingAs($owner)->postJson("/api/v1/organizations/{$org->id}/audit-ai", ['audit_ids' => [$auditId]])->assertOk()->assertJsonCount(1, 'data.history');
        $this->actingAs($owner)->postJson("/api/v1/organizations/{$org->id}/audit-ai", ['history_only' => true])->assertOk()->assertJsonCount(1, 'data.history');

        // 3. Platform Health Triage AI history
        $this->actingAs($admin)->postJson('/api/v1/platform/health-triage-ai', ['days' => 7])->assertOk()->assertJsonCount(1, 'data.history');
        $this->actingAs($admin)->postJson('/api/v1/platform/health-triage-ai', ['history_only' => true])->assertOk()->assertJsonCount(1, 'data.history');

        // 4. Journey CO2 estimate + Report retention window metadata
        $journeyId = (string) Str::uuid();
        $from = $start;
        $to = $start->addHour();
        DB::table('journeys')->insert([
            'id' => $journeyId,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicleId,
            'state' => 'completed',
            'started_at' => $from,
            'ended_at' => $to,
            'last_observed_at' => $to,
            'start_observation_id' => (string) Str::uuid(),
            'end_reason' => 'stationary_engine_off',
            'unknown_seconds' => 0,
            'detection_version' => 'pilot-v1',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([$from, $to] as $idx => $ts) {
            $inboxId = (string) Str::uuid();
            DB::table('telemetry_inbox')->insert([
                'id' => $inboxId,
                'organization_id' => $org->id,
                'device_id' => $deviceId,
                'installation_id' => (string) Str::uuid(),
                'event_id' => (string) Str::uuid(),
                'occurred_at' => $ts,
                'received_at' => now(),
                'payload_sha256' => hash('sha256', $inboxId.$idx),
                'raw_payload' => '{}',
                'state' => 'projected',
            ]);
            DB::table('telemetry_samples')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'vehicle_id' => $vehicleId,
                'installation_id' => (string) Str::uuid(),
                'inbox_id' => $inboxId,
                'occurred_at' => $ts,
                'received_at' => now(),
                'speed_kmh' => 60.0,
                'fuel_rate_lph' => 6.0,
                'quality' => '{}',
                'created_at' => now(),
            ]);
        }

        $this->actingAs($owner)
            ->getJson("/api/v1/organizations/{$org->id}/journeys/{$journeyId}")
            ->assertOk()
            ->assertJsonPath('data.journey.fuel_litres', 6)
            ->assertJsonPath('data.journey.estimated_co2_kg', 13.86);

        $this->actingAs($owner)
            ->getJson("/api/v1/organizations/{$org->id}/reports/summary")
            ->assertOk()
            ->assertJsonPath('data.fleet_summary.retention_days', 30);
    }
}
