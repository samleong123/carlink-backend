<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditLogsAndAiTest extends TestCase
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
            'dtc_ai.key' => 'private-gemini-key',
            'dtc_ai.audit_model' => 'gemini-3.5-flash-lite',
        ]);

        $owner = User::create([
            'email_normalized' => 'owner@example.test',
            'display_name' => 'Fleet Owner',
        ]);
        $org = Organization::create(['name' => 'Audit Org', 'slug' => 'audit-org']);
        Membership::create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $planId = (string) Str::uuid();
        $plan = [
            'id' => $planId,
            'name' => 'Standard',
            'price_minor' => 1000,
            'currency' => 'MYR',
            'period_days' => 30,
            'vehicle_limit' => 5,
            'driver_limit' => 5,
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
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::create(['email_normalized' => 'saas@example.test', 'display_name' => 'SaaS Admin']);
        DB::table('saas_admins')->insert([
            'user_id' => $admin->id,
            'role' => 'admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$owner, $org, $admin];
    }

    public function test_request_logs_return_normalized_route_status_ip_and_user_agent_for_tenant_and_platform(): void
    {
        [$owner, $org, $admin] = $this->fixture();

        $hit = $this->actingAs($owner)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders([
                'User-Agent' => 'CarLinkBrowser/1.0',
                'Sec-CH-UA' => '"Google Chrome";v="131"',
                'Sec-CH-UA-Platform' => '"Windows"',
            ])
            ->getJson("/api/v1/organizations/{$org->id}/vehicles")
            ->assertOk();

        $targetRequestId = $hit->json('request_id');
        $query = '?from=' . urlencode(now()->subHour()->toIso8601String()) . '&to=' . urlencode(now()->addHour()->toIso8601String());

        $tenantResponse = $this->actingAs($owner)
            ->getJson("/api/v1/organizations/{$org->id}/request-logs{$query}")
            ->assertOk();

        $tenantItem = collect($tenantResponse->json('data.items'))->firstWhere('request_id', $targetRequestId);
        $this->assertNotNull($tenantItem);
        $this->assertSame($org->id, $tenantItem['organization_id']);
        $this->assertSame('GET', $tenantItem['method']);
        $this->assertSame('api/v1/organizations/{organization}/vehicles', $tenantItem['route']);
        $this->assertSame($tenantItem['route'], $tenantItem['route_template']);
        $this->assertSame(200, $tenantItem['http_status']);
        $this->assertSame(200, $tenantItem['status']);
        $this->assertSame('203.0.113.10', $tenantItem['ip_address']);
        $this->assertSame('CarLinkBrowser/1.0', $tenantItem['user_agent']);
        $this->assertSame('Google Chrome 131 · Windows', $tenantItem['browser']);
        $this->assertIsInt($tenantItem['duration_ms']);

        $platformResponse = $this->actingAs($admin)
            ->getJson("/api/v1/platform/request-logs{$query}")
            ->assertOk();

        $platformItem = collect($platformResponse->json('data.items'))->firstWhere('request_id', $targetRequestId);
        $this->assertNotNull($platformItem);
        $this->assertSame('api/v1/organizations/{organization}/vehicles', $platformItem['route']);
        $this->assertSame('api/v1/organizations/{organization}/vehicles', $platformItem['route_template']);
        $this->assertSame(200, $platformItem['http_status']);
        $this->assertSame(200, $platformItem['status']);
        $this->assertSame('203.0.113.10', $platformItem['ip_address']);
        $this->assertSame('CarLinkBrowser/1.0', $platformItem['user_agent']);
        $this->assertSame('Google Chrome 131 · Windows', $platformItem['browser']);
    }

    public function test_audit_logs_hydrate_actors_correlate_http_metadata_and_sanitize_context(): void
    {
        [$owner, $org] = $this->fixture();

        $hit = $this->actingAs($owner)
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.24'])
            ->withHeaders(['User-Agent' => 'AuditClient/2.0'])
            ->postJson("/api/v1/organizations/{$org->id}/vehicles", [
                'registration_number' => 'WXY1234',
                'registration_country' => 'MY',
            ])
            ->assertCreated();

        $reqId = $hit->json('request_id');
        $workerAuditId = (string) Str::uuid();
        DB::connection('logging')->table('audit_logs')->insert([
            'id' => $workerAuditId,
            'organization_id' => $org->id,
            'user_id' => null,
            'membership_id' => null,
            'request_id' => null,
            'origin_request_id' => $reqId,
            'source' => 'worker',
            'action_name' => 'telemetry.project_batch',
            'outcome' => 'succeeded',
            'occurred_at' => CarbonImmutable::now('UTC'),
            'context' => json_encode([
                'organization_id' => $org->id,
                'origin_request_id' => $reqId,
                'auth_token' => 'secret-token-must-not-leak',
                'cookie' => 'session=secret',
                'batch_size' => 4,
            ]),
        ]);

        $query = '?from=' . urlencode(now()->subHour()->toIso8601String()) . '&to=' . urlencode(now()->addHour()->toIso8601String());
        $response = $this->actingAs($owner)
            ->getJson("/api/v1/organizations/{$org->id}/audit-logs{$query}")
            ->assertOk();

        $items = collect($response->json('data.items'));
        $httpAudit = $items->first(fn ($item) => $item['request_id'] === $reqId && $item['outcome'] === 'succeeded' && $item['source'] === 'http');
        $this->assertNotNull($httpAudit);
        $this->assertSame($owner->id, $httpAudit['actor_user_id']);
        $this->assertSame('Fleet Owner (owner@example.test)', $httpAudit['actor_name']);
        $this->assertSame('POST api/v1/organizations/{organization}/vehicles', $httpAudit['action']);
        $this->assertSame('POST', $httpAudit['method']);
        $this->assertSame('api/v1/organizations/{organization}/vehicles', $httpAudit['route']);
        $this->assertSame(201, $httpAudit['http_status']);
        $this->assertSame('198.51.100.24', $httpAudit['ip_address']);
        $this->assertSame('AuditClient/2.0', $httpAudit['user_agent']);

        $workerAudit = $items->firstWhere('id', $workerAuditId);
        $this->assertNotNull($workerAudit);
        $this->assertNull($workerAudit['actor_user_id']);
        $this->assertSame('system (worker)', $workerAudit['actor_name']);
        $this->assertSame($reqId, $workerAudit['request_id']);
        $this->assertSame('POST', $workerAudit['method']);
        $this->assertSame(201, $workerAudit['http_status']);
        $this->assertSame(4, $workerAudit['context']['batch_size']);
        $this->assertArrayNotHasKey('auth_token', $workerAudit['context']);
        $this->assertArrayNotHasKey('cookie', $workerAudit['context']);
    }

    public function test_audit_ai_enforces_permissions_tenant_scope_validation_and_parses_gemini_output(): void
    {
        [$owner, $org] = $this->fixture();

        $driver = User::create(['email_normalized' => 'driver@example.test', 'display_name' => 'Driver']);
        Membership::create([
            'organization_id' => $org->id,
            'user_id' => $driver->id,
            'role' => 'driver',
            'status' => 'active',
        ]);

        $otherOrg = Organization::create(['name' => 'Other Org', 'slug' => 'other-org']);
        $otherAuditId = (string) Str::uuid();
        DB::connection('logging')->table('audit_logs')->insert([
            'id' => $otherAuditId,
            'organization_id' => $otherOrg->id,
            'user_id' => null,
            'membership_id' => null,
            'request_id' => null,
            'origin_request_id' => null,
            'source' => 'http',
            'action_name' => 'GET api/v1/organizations/{organization}/vehicles',
            'outcome' => 'succeeded',
            'occurred_at' => CarbonImmutable::now('UTC'),
            'context' => json_encode(['organization_id' => $otherOrg->id]),
        ]);

        $audit1 = (string) Str::uuid();
        $audit2 = (string) Str::uuid();
        DB::connection('logging')->table('audit_logs')->insert([
            [
                'id' => $audit1,
                'organization_id' => $org->id,
                'user_id' => $owner->id,
                'membership_id' => null,
                'request_id' => null,
                'origin_request_id' => null,
                'source' => 'http',
                'action_name' => 'POST api/v1/organizations/{organization}/vehicles',
                'outcome' => 'succeeded',
                'occurred_at' => CarbonImmutable::now('UTC')->subMinute(),
                'context' => json_encode(['method' => 'POST', 'route' => 'api/v1/organizations/{organization}/vehicles', 'status' => 201, 'ip_address' => '203.0.113.99']),
            ],
            [
                'id' => $audit2,
                'organization_id' => $org->id,
                'user_id' => null,
                'membership_id' => null,
                'request_id' => null,
                'origin_request_id' => null,
                'source' => 'scheduler',
                'action_name' => 'maintenance.evaluate',
                'outcome' => 'succeeded',
                'occurred_at' => CarbonImmutable::now('UTC'),
                'context' => json_encode(['evaluated' => 3]),
            ],
        ]);

        Http::preventStrayRequests();

        // Permission check (driver lacks audit.read).
        $this->actingAs($driver)
            ->postJson("/api/v1/organizations/{$org->id}/audit-ai", ['audit_ids' => [$audit1]])
            ->assertForbidden();

        // Validation checks.
        $this->actingAs($owner)
            ->postJson("/api/v1/organizations/{$org->id}/audit-ai", ['audit_ids' => []])
            ->assertUnprocessable();
        $this->actingAs($owner)
            ->postJson("/api/v1/organizations/{$org->id}/audit-ai", ['audit_ids' => ['not-a-uuid']])
            ->assertUnprocessable();
        $this->actingAs($owner)
            ->postJson("/api/v1/organizations/{$org->id}/audit-ai", ['audit_ids' => [$audit1, $audit1]])
            ->assertUnprocessable();

        // Cross-tenant isolation: selecting another tenant's audit ID returns 404.
        $this->actingAs($owner)
            ->postJson("/api/v1/organizations/{$org->id}/audit-ai", ['audit_ids' => [$otherAuditId]])
            ->assertNotFound();

        Http::assertNothingSent();

        // Valid multi-row analysis with markdown-wrapped JSON from Gemini.
        $geminiJson = json_encode([
            'summary' => 'The fleet owner registered a vehicle and the scheduler subsequently evaluated maintenance schedules.',
            'items' => [
                [
                    'id' => $audit1,
                    'action' => 'POST api/v1/organizations/{organization}/vehicles',
                    'summary' => 'Created a new vehicle record in the organization.',
                    'intent' => 'Vehicle registration',
                    'risk_or_status' => 'Normal · Succeeded',
                ],
                [
                    'id' => $audit2,
                    'action' => 'maintenance.evaluate',
                    'summary' => 'Background scheduler evaluated 3 maintenance assignments.',
                    'intent' => 'Scheduled maintenance check',
                    'risk_or_status' => 'Normal · Succeeded',
                ],
            ],
        ]);

        $fakeMode = 'ok';
        Http::fake(function () use (&$fakeMode, $geminiJson) {
            if ($fakeMode === 'error_500') {
                return Http::response(['error' => ['message' => 'bad']], 500);
            }
            if ($fakeMode === 'error_503') {
                return Http::response(['error' => ['message' => 'capacity']], 503);
            }
            return Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => "```json\n{$geminiJson}\n```"],
                        ],
                    ],
                ]],
            ]);
        });

        $res = $this->actingAs($owner)
            ->postJson("/api/v1/organizations/{$org->id}/audit-ai", [
                'audit_ids' => [$audit1, $audit2],
            ])
            ->assertOk()
            ->assertJsonPath('data.model', 'gemini-3.5-flash-lite')
            ->assertJsonPath('data.items.0.id', $audit1)
            ->assertJsonPath('data.items.1.id', $audit2);

        $this->assertStringContainsString('registered a vehicle', $res->json('data.summary'));

        Http::assertSent(function ($request) {
            $bodyText = $request['contents'][0]['parts'][0]['text'] ?? '';
            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent'
                && $request->hasHeader('x-goog-api-key', 'private-gemini-key')
                && !str_contains($request->url(), 'key=')
                && !str_contains($bodyText, 'owner@example.test')
                && !str_contains($bodyText, '203.0.113.99');
        });

        // Provider error and missing API key checks.
        $fakeMode = 'error_500';
        $this->actingAs($owner)
            ->postJson("/api/v1/organizations/{$org->id}/audit-ai", ['audit_ids' => [$audit1]])
            ->assertStatus(502)
            ->assertJsonPath('desc', 'Please try again later due to Google Gemini capacity issue')
            ->assertJsonPath('data.error.code', 'GEMINI_CAPACITY_ISSUE');

        $fakeMode = 'error_503';
        $this->actingAs($owner)
            ->postJson("/api/v1/organizations/{$org->id}/audit-ai", ['audit_ids' => [$audit1]])
            ->assertStatus(502)
            ->assertJsonPath('desc', 'Please try again later due to Google Gemini capacity issue')
            ->assertJsonPath('data.error.code', 'GEMINI_CAPACITY_ISSUE');

        config(['dtc_ai.key' => '']);
        $this->actingAs($owner)
            ->postJson("/api/v1/organizations/{$org->id}/audit-ai", ['audit_ids' => [$audit1]])
            ->assertStatus(503);
    }
}
