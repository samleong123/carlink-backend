<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceSettingsSyncTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['saas.enforce_subscriptions' => true]);
        $owner = User::create(['email_normalized' => 'owner@example.test', 'display_name' => 'Owner']);
        $org = Organization::create(['name' => 'Fleet', 'slug' => 'fleet']);
        Membership::create(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);

        $plan = (string) Str::uuid();
        $terms = [
            'id' => $plan,
            'name' => 'Pilot',
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
        DB::table('subscription_plans')->insert($terms + ['created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_subscriptions')->insert([
            'organization_id' => $org->id,
            'plan_id' => $plan,
            'plan_snapshot' => json_encode($terms, JSON_THROW_ON_ERROR),
            'status' => 'active',
            'expires_at' => now()->addDays(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vehicle = (string) Str::uuid();
        $device = (string) Str::uuid();
        $installation = (string) Str::uuid();
        $token = str_repeat('a', 64);

        DB::table('vehicles')->insert([
            'id' => $vehicle,
            'organization_id' => $org->id,
            'registration_number' => 'VFR7550',
            'registration_normalized' => 'VFR7550',
            'registration_country' => 'MY',
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('devices')->insert([
            'id' => $device,
            'organization_id' => $org->id,
            'serial_number' => 'pi-001',
            'broker_principal' => $device,
            'status' => 'active',
            'auth_token_encrypted' => Crypt::encryptString($token),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('device_installations')->insert([
            'id' => $installation,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle,
            'device_id' => $device,
            'effective_from' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$owner, $org, $device, $token, $installation];
    }

    public function test_device_settings_requires_valid_active_device_token_and_installation(): void
    {
        [, , $device, $token, $installation] = $this->fixture();
        $url = "/api/v1/device/{$device}/settings";

        $this->getJson($url)->assertUnauthorized();
        $this->getJson($url, ['Authorization' => 'Bearer '.str_repeat('b', 64)])->assertUnauthorized();

        DB::table('device_installations')->where('id', $installation)->update(['effective_to' => now()]);
        $this->getJson($url, ['Authorization' => 'Bearer '.$token])->assertForbidden();
    }

    public function test_device_settings_and_commands_advertise_effective_settings_and_version_updates(): void
    {
        [$owner, $org, $device, $token] = $this->fixture();
        $headers = ['Authorization' => 'Bearer '.$token];

        // Default settings before any organization_settings row exists
        $this->getJson("/api/v1/device/{$device}/commands", $headers)
            ->assertOk()
            ->assertJsonPath('data.settings_version', 1);

        $this->getJson("/api/v1/device/{$device}/settings", $headers)
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.settings.telemetry_interval_seconds', 5)
            ->assertJsonPath('data.settings.cellular_enabled', true)
            ->assertJsonPath('data.settings.safety_enabled', true)
            ->assertJsonPath('data.settings.harsh_braking_ms2', 3)
            ->assertJsonPath('data.settings.rapid_acceleration_ms2', 3)
            ->assertJsonPath('data.settings.sharp_cornering_ms2', 3.5);

        // Tenant admin updates sampling interval and IMU thresholds -> bumps version to 2
        $update = $this->actingAs($owner)->patchJson("/api/v1/organizations/{$org->id}/settings", [
            'version' => 1,
            'settings' => [
                'telemetry_interval_seconds' => 4,
                'harsh_braking_ms2' => 4.25,
                'rapid_acceleration_ms2' => 3.75,
            ],
        ])->assertOk()->assertJsonPath('data.version', 2);

        $this->assertStringContainsString('boot', strtolower((string) $update->json('data.device_delivery')));
        $this->assertStringContainsString('poll', strtolower((string) $update->json('data.device_delivery')));

        // Every /commands poll now advertises settings_version = 2
        $this->getJson("/api/v1/device/{$device}/commands", $headers)
            ->assertOk()
            ->assertJsonPath('data.settings_version', 2);

        // /settings returns the new effective settings at version 2
        $this->getJson("/api/v1/device/{$device}/settings", $headers)
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.settings.telemetry_interval_seconds', 4)
            ->assertJsonPath('data.settings.harsh_braking_ms2', 4.25)
            ->assertJsonPath('data.settings.rapid_acceleration_ms2', 3.75)
            ->assertJsonPath('data.settings.sharp_cornering_ms2', 3.5);

        // Plan entitlements clamp telemetry_interval_seconds and gate cellular/safety features
        $planSnapshot = json_decode(DB::table('organization_subscriptions')->where('organization_id', $org->id)->value('plan_snapshot'), true);
        $planSnapshot['telemetry_min_seconds'] = 10;
        $planSnapshot['cellular_enabled'] = false;
        $planSnapshot['safety_enabled'] = false;
        DB::table('organization_subscriptions')->where('organization_id', $org->id)->update([
            'plan_snapshot' => json_encode($planSnapshot, JSON_THROW_ON_ERROR),
        ]);

        $this->getJson("/api/v1/device/{$device}/settings", $headers)
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.settings.telemetry_interval_seconds', 10)
            ->assertJsonPath('data.settings.cellular_enabled', false)
            ->assertJsonPath('data.settings.safety_enabled', false);

        // Stationary DTC check honors organization device_offline_after_s window
        $commands = app(\App\Services\DeviceCommands::class);
        $this->assertSame(180, $commands->stationaryMaxAgeSeconds($org->id));

        DB::table('organization_settings')->where('organization_id', $org->id)->update([
            'settings' => json_encode(['device_offline_after_s' => 300], JSON_THROW_ON_ERROR),
        ]);
        $this->assertSame(300, $commands->stationaryMaxAgeSeconds($org->id));
    }
}
