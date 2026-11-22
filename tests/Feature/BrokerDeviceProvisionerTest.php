<?php

namespace Tests\Feature;

use App\Models\{Organization, User};
use App\Services\Broker\BrokerDeviceProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Crypt, DB, Http, Log};
use Illuminate\Support\Str;
use Tests\TestCase;

class BrokerDeviceProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config([
            'saas.enforce_subscriptions' => true,
            'saas.managed_devices' => true,
            'saas.broker_url' => 'wss://broker.example.test/ws',
            'broker.verified_topic_acls' => true,
            'broker.vhost' => 'carlink',
            'broker.exchange' => 'amq.topic',
        ]);

        $tech = User::create(['email_normalized' => 'tech@example.test', 'display_name' => 'Tech', 'status' => 'active']);
        DB::table('saas_admins')->insert(['user_id' => $tech->id, 'role' => 'technician', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $org = Organization::create(['name' => 'Fleet', 'slug' => 'fleet-'.Str::random(6)]);
        $vehicleId = (string) Str::uuid();
        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $org->id,
            'registration_number' => 'VFR7550',
            'registration_normalized' => 'VFR7550',
            'registration_country' => 'MY',
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deviceId = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        DB::table('devices')->insert([
            'id' => $deviceId,
            'organization_id' => $org->id,
            'serial_number' => 'pi-'.$deviceId,
            'status' => 'registered',
            'obd_uuid' => (string) Str::uuid(),
            'obd_bluetooth_mac' => 'AA:BB:CC:DD:EE:FF',
            'registered_vehicle_id' => $vehicleId,
            'auth_token_encrypted' => Crypt::encryptString($token),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tech, $org, $deviceId, $token];
    }

    public function test_provisioning_is_disabled_when_management_url_is_empty(): void
    {
        config(['broker.management.url' => '']);
        Http::preventStrayRequests();
        [$tech, , $deviceId] = $this->fixture();

        $this->actingAs($tech)->postJson("/api/v1/platform/devices/{$deviceId}/commissioning")
            ->assertOk()
            ->assertJsonPath('data.rabbitmq.status', 'disabled')
            ->assertJsonPath('data.rabbitmq.action', 'skipped');

        $this->actingAs($tech)->postJson("/api/v1/platform/devices/{$deviceId}/activate", [
            'version' => 1,
            'installed' => true,
            'verified_broker_acls' => true,
        ])->assertOk();

        $this->assertFalse(app(BrokerDeviceProvisioner::class)->enabled());
    }

    public function test_commissioning_reports_created_then_overridden_and_activation_provisions_acls(): void
    {
        config([
            'broker.management.url' => 'http://172.18.0.15:15672/',
            'broker.management.user' => 'carlink_provisioner',
            'broker.management.password' => 'provisioner-secret-pass',
        ]);
        [$tech, $org, $deviceId, $token] = $this->fixture();
        $principal = 'd-'.str_replace('-', '', $deviceId);

        Http::preventStrayRequests();
        Http::fake([
            "http://172.18.0.15:15672/api/users/{$principal}" => Http::sequence()
                ->push(['error' => 'Object Not Found'], 404)
                ->push('', 201)
                ->push(['name' => $principal, 'tags' => []], 200)
                ->push('', 204)
                ->push(['name' => $principal, 'tags' => []], 200)
                ->push('', 204),
            "http://172.18.0.15:15672/api/permissions/carlink/{$principal}" => Http::response('', 201),
            "http://172.18.0.15:15672/api/topic-permissions/carlink/{$principal}" => Http::response('', 201),
        ]);

        // First call creates the user
        $this->actingAs($tech)->postJson("/api/v1/platform/devices/{$deviceId}/commissioning")
            ->assertOk()
            ->assertJsonPath('data.rabbitmq.status', 'success')
            ->assertJsonPath('data.rabbitmq.action', 'created')
            ->assertJsonPath('data.rabbitmq.overridden', false)
            ->assertJsonPath('data.rabbitmq.http_status', 201);

        // Second call overrides the existing device user
        $this->actingAs($tech)->postJson("/api/v1/platform/devices/{$deviceId}/commissioning")
            ->assertOk()
            ->assertJsonPath('data.rabbitmq.status', 'success')
            ->assertJsonPath('data.rabbitmq.action', 'overridden')
            ->assertJsonPath('data.rabbitmq.overridden', true)
            ->assertJsonPath('data.rabbitmq.http_status', 204);

        $this->actingAs($tech)->postJson("/api/v1/platform/devices/{$deviceId}/activate", [
            'version' => 1,
            'installed' => true,
            'verified_broker_acls' => true,
        ])->assertOk();

        $expectedAuth = 'Basic '.base64_encode('carlink_provisioner:provisioner-secret-pass');
        Http::assertSent(function ($req) use ($principal, $token, $expectedAuth) {
            return $req->method() === 'PUT'
                && $req->url() === "http://172.18.0.15:15672/api/users/{$principal}"
                && $req->header('Authorization')[0] === $expectedAuth
                && $req['password'] === $token
                && $req['tags'] === '';
        });

        Http::assertSent(function ($req) use ($principal, $org, $deviceId) {
            $prefix = preg_quote("fleet.v1.{$org->id}.{$deviceId}", '/');
            return $req->method() === 'PUT'
                && $req->url() === "http://172.18.0.15:15672/api/topic-permissions/carlink/{$principal}"
                && $req['exchange'] === 'amq.topic'
                && $req['write'] === "^{$prefix}\\.up\\.(telemetry|events)$"
                && $req['read'] === "^{$prefix}\\.down\\.(ingest_ack|results)$";
        });
    }

    public function test_commissioning_returns_failed_status_and_logs_error_while_activate_fails_closed(): void
    {
        config([
            'broker.management.url' => 'http://172.18.0.15:15672',
            'broker.management.user' => 'carlink_provisioner',
            'broker.management.password' => 'provisioner-secret-pass',
        ]);
        [$tech, , $deviceId] = $this->fixture();
        $principal = 'd-'.str_replace('-', '', $deviceId);

        Log::spy();
        Http::preventStrayRequests();
        Http::fake([
            "http://172.18.0.15:15672/api/users/{$principal}" => Http::sequence()
                ->push(['name' => $principal, 'tags' => ['administrator']], 200)
                ->push(['error' => 'Object Not Found'], 404)
                ->push(['error' => 'not_authorised', 'reason' => 'Login failed'], 401)
                ->push(['error' => 'Object Not Found'], 404)
                ->push('', 500),
        ]);

        // Conflict with privileged user returns failed status on commissioning and logs error
        $this->actingAs($tech)->postJson("/api/v1/platform/devices/{$deviceId}/commissioning")
            ->assertOk()
            ->assertJsonPath('data.rabbitmq.status', 'failed')
            ->assertJsonPath('data.rabbitmq.http_status', 409);

        // Management API HTTP 401 returns failed status on commissioning and logs error
        $this->actingAs($tech)->postJson("/api/v1/platform/devices/{$deviceId}/commissioning")
            ->assertOk()
            ->assertJsonPath('data.rabbitmq.status', 'failed')
            ->assertJsonPath('data.rabbitmq.http_status', 401);

        // Activate still fails closed on RabbitMQ error
        $this->actingAs($tech)->postJson("/api/v1/platform/devices/{$deviceId}/activate", [
            'version' => 1,
            'installed' => true,
            'verified_broker_acls' => true,
        ])->assertStatus(503);

        Log::shouldHaveReceived('error')->times(3);
        $this->assertDatabaseHas('devices', ['id' => $deviceId, 'status' => 'registered']);
    }
}
