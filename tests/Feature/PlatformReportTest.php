<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformReportTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): array
    {
        $admin = User::create(['email_normalized' => 'platform-admin@example.test', 'display_name' => 'Platform Admin']);
        DB::table('saas_admins')->insert([
            'user_id' => $admin->id,
            'role' => 'admin',
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$admin];
    }

    public function test_unauthenticated_or_non_admin_cannot_access_platform_report(): void
    {
        $this->getJson('/api/v1/platform/reports/summary')->assertUnauthorized();

        $regularUser = User::create(['email_normalized' => 'regular@example.test', 'display_name' => 'Regular User']);
        $this->actingAs($regularUser)->getJson('/api/v1/platform/reports/summary')->assertForbidden();

        $tech = User::create(['email_normalized' => 'tech@example.test', 'display_name' => 'Tech']);
        DB::table('saas_admins')->insert([
            'user_id' => $tech->id,
            'role' => 'technician',
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($tech)->getJson('/api/v1/platform/reports/summary')->assertForbidden();
    }

    public function test_platform_report_returns_all_keys_with_graceful_logging_degradation(): void
    {
        [$admin] = $this->createAdmin();

        $org = Organization::create(['name' => 'Acme Corp', 'slug' => 'acme-corp']);

        $planId = (string) Str::uuid();
        DB::table('subscription_plans')->insert([
            'id' => $planId,
            'name' => 'Enterprise',
            'price_minor' => 50000,
            'currency' => 'MYR',
            'period_days' => 30,
            'vehicle_limit' => 10,
            'driver_limit' => 10,
            'retention_days' => 90,
            'cellular_enabled' => true,
            'safety_enabled' => true,
            'telemetry_min_seconds' => 2,
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_subscriptions')->insert([
            'organization_id' => $org->id,
            'plan_id' => $planId,
            'status' => 'active',
            'expires_at' => now()->addDays(20),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vehicleId = (string) Str::uuid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $org->id,
            'registration_number' => 'W1234A',
            'registration_normalized' => 'W1234A',
            'registration_country' => 'MY',
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('drivers')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $org->id,
            'name' => 'John Doe',
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('devices')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $org->id,
            'serial_number' => 'DEV-001',
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('billing_invoices')->insert([
            'id' => (string) Str::uuid(),
            'number' => 'INV-2026-001',
            'organization_id' => $org->id,
            'plan_id' => $planId,
            'plan_snapshot' => json_encode(['name' => 'Enterprise']),
            'amount_minor' => 50000,
            'currency' => 'MYR',
            'status' => 'paid',
            'issued_by' => $admin->id,
            'paid_at' => now()->subDays(5),
            'version' => 1,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $res = $this->actingAs($admin)->getJson('/api/v1/platform/reports/summary')->assertOk();

        // Existing keys
        $res->assertJsonStructure([
            'data' => [
                'as_of',
                'organizations',
                'active_paid_subscriptions',
                'registered_devices',
                'activated_devices',
                'paid_invoice_total_minor',
                'currency',
                'total_users',
                'total_vehicles',
                'total_drivers',
                'saas_staff_count',
                'subscriptions_by_status',
                'devices_by_status',
                'paid_invoices_count',
                'revenue_30d_minor',
                'telemetry_samples_24h',
                'requests_24h',
                'requests_7d',
                'error_requests_24h',
                'server_errors_24h',
                'avg_latency_ms_24h',
                'daily_requests_7d',
                'top_endpoints_24h',
            ],
        ]);

        $data = $res->json('data');
        $this->assertSame(1, $data['organizations']);
        $this->assertSame(1, $data['active_paid_subscriptions']);
        $this->assertSame(1, $data['registered_devices']);
        $this->assertSame(1, $data['activated_devices']);
        $this->assertSame(50000, $data['paid_invoice_total_minor']);
        $this->assertSame('MYR', $data['currency']);

        $this->assertSame(1, $data['total_vehicles']);
        $this->assertSame(1, $data['total_drivers']);
        $this->assertSame(1, $data['saas_staff_count']);
        $this->assertSame(1, $data['subscriptions_by_status']['active']);
        $this->assertSame(1, $data['devices_by_status']['active']);
        $this->assertSame(1, $data['paid_invoices_count']);
        $this->assertSame(50000, $data['revenue_30d_minor']);

        // Logging was not set up, so degrades gracefully
        $this->assertNull($data['requests_24h']);
        $this->assertNull($data['requests_7d']);
        $this->assertNull($data['error_requests_24h']);
        $this->assertNull($data['server_errors_24h']);
        $this->assertNull($data['avg_latency_ms_24h']);
        $this->assertSame([], $data['daily_requests_7d']);
        $this->assertSame([], $data['top_endpoints_24h']);
    }

    public function test_platform_report_with_populated_request_logs(): void
    {
        [$admin] = $this->createAdmin();

        // Create http_request_logs table on the logging connection
        Schema::connection('logging')->create('http_request_logs', function (Blueprint $t) {
            $t->uuid('request_id')->primary();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->string('state', 24)->default('completed');
            $t->uuid('organization_id')->nullable();
            $t->uuid('user_id')->nullable();
            $t->uuid('membership_id')->nullable();
            $t->string('method', 16)->nullable();
            $t->string('route', 255)->nullable();
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->unsignedBigInteger('duration_ms')->nullable();
            $t->json('context')->nullable();
        });

        $now = CarbonImmutable::now();

        // 1. Success request 2 hours ago (200, 50ms)
        DB::connection('logging')->table('http_request_logs')->insert([
            'request_id' => (string) Str::uuid(),
            'started_at' => $now->subHours(2)->toIso8601String(),
            'completed_at' => $now->subHours(2)->addMilliseconds(50)->toIso8601String(),
            'state' => 'completed',
            'method' => 'GET',
            'route' => '/api/v1/organizations/{org}/vehicles',
            'http_status' => 200,
            'duration_ms' => 50,
            'context' => '{}',
        ]);

        // 2. Client error request 3 hours ago (404, 30ms)
        DB::connection('logging')->table('http_request_logs')->insert([
            'request_id' => (string) Str::uuid(),
            'started_at' => $now->subHours(3)->toIso8601String(),
            'completed_at' => $now->subHours(3)->addMilliseconds(30)->toIso8601String(),
            'state' => 'completed',
            'method' => 'GET',
            'route' => '/api/v1/organizations/{org}/vehicles',
            'http_status' => 404,
            'duration_ms' => 30,
            'context' => '{}',
        ]);

        // 3. Server error request 1 hour ago (500, 100ms)
        DB::connection('logging')->table('http_request_logs')->insert([
            'request_id' => (string) Str::uuid(),
            'started_at' => $now->subHours(1)->toIso8601String(),
            'completed_at' => $now->subHours(1)->addMilliseconds(100)->toIso8601String(),
            'state' => 'failed',
            'method' => 'POST',
            'route' => '/api/v1/organizations/{org}/devices',
            'http_status' => 500,
            'duration_ms' => 100,
            'context' => '{}',
        ]);

        // 4. Request 3 days ago (200, 40ms) - should count in requests_7d, but not 24h
        DB::connection('logging')->table('http_request_logs')->insert([
            'request_id' => (string) Str::uuid(),
            'started_at' => $now->subDays(3)->toIso8601String(),
            'completed_at' => $now->subDays(3)->addMilliseconds(40)->toIso8601String(),
            'state' => 'completed',
            'method' => 'GET',
            'route' => '/api/v1/organizations/{org}/dashboard',
            'http_status' => 200,
            'duration_ms' => 40,
            'context' => '{}',
        ]);

        $res = $this->actingAs($admin)->getJson('/api/v1/platform/reports/summary')->assertOk();
        $data = $res->json('data');

        $this->assertSame(3, $data['requests_24h']);
        $this->assertSame(4, $data['requests_7d']);
        $this->assertSame(2, $data['error_requests_24h']); // 404 and 500
        $this->assertSame(1, $data['server_errors_24h']);  // 500
        $this->assertEquals(60.0, $data['avg_latency_ms_24h']); // (50 + 30 + 100) / 3 = 60.0

        // 7-day breakdown: array of 7 items
        $this->assertCount(7, $data['daily_requests_7d']);
        $todayEntry = collect($data['daily_requests_7d'])->firstWhere('date', $now->format('Y-m-d'));
        $this->assertNotNull($todayEntry);
        $this->assertSame(3, $todayEntry['count']);
        $this->assertSame(2, $todayEntry['errors']);

        // Top endpoints
        $this->assertNotEmpty($data['top_endpoints_24h']);
        $top = $data['top_endpoints_24h'][0];
        $this->assertSame('GET', $top['method']);
        $this->assertSame('/api/v1/organizations/{org}/vehicles', $top['path']);
        $this->assertSame(2, $top['count']);
    }
}
