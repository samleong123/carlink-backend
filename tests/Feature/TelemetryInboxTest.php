<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\Telemetry\TelemetryInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelemetryInboxTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['broker.verified_topic_acls' => true]);
        $org = Organization::create(['name' => 'Fleet', 'slug' => 'fleet']);
        $vehicle = (string) Str::uuid();
        $device = (string) Str::uuid();
        $installation = (string) Str::uuid();
        DB::table('vehicles')->insert(['id' => $vehicle, 'organization_id' => $org->id, 'registration_number' => 'ABC123', 'registration_normalized' => 'ABC123', 'registration_country' => 'MY', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('devices')->insert(['id' => $device, 'organization_id' => $org->id, 'serial_number' => 'pi-1', 'status' => 'active', 'broker_principal' => 'provisioned-pi-1', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('device_installations')->insert(['id' => $installation, 'organization_id' => $org->id, 'device_id' => $device, 'vehicle_id' => $vehicle, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $envelope = ['schema_version' => '1.0', 'message_type' => 'telemetry', 'event_id' => (string) Str::uuid(), 'device_id' => $device, 'installation_id' => $installation, 'boot_id' => (string) Str::uuid(), 'sequence' => 1, 'occurred_at' => now()->utc()->toIso8601String(), 'sent_at' => now()->utc()->toIso8601String(), 'uptime_ms' => 100, 'edge_version' => '1.0', 'config_version' => 1, 'clock' => (object) [], 'payload' => (object) []];
        return [$org, $device, $envelope];
    }

    public function test_inbox_is_disabled_without_operator_attestation(): void
    {
        config(['broker.verified_topic_acls' => false]);
        $this->expectException(\LogicException::class);
        app(TelemetryInbox::class)->receive('fleet.v1.invalid', '{}');
    }

    public function test_store_deduplicates_and_quarantines_conflicting_payload(): void
    {
        [$org, $device, $message] = $this->fixture();
        $route = "fleet.v1.{$org->id}.{$device}.up.telemetry";
        $body = json_encode($message, JSON_THROW_ON_ERROR);
        $service = app(TelemetryInbox::class);
        $this->assertSame('stored', $service->receive($route, $body));
        $this->assertSame('duplicate', $service->receive($route, $body));
        $message['sequence'] = 2;
        $this->assertSame('quarantined', $service->receive($route, json_encode($message, JSON_THROW_ON_ERROR)));
        $this->assertDatabaseCount('telemetry_inbox', 1);
        $this->assertDatabaseHas('telemetry_quarantine', ['reason' => 'conflicting_event']);
    }

    public function test_forged_device_and_unprovisioned_route_are_quarantined(): void
    {
        [$org, $device, $message] = $this->fixture();
        $service = app(TelemetryInbox::class);
        $route = "fleet.v1.{$org->id}.{$device}.up.telemetry";
        $message['device_id'] = (string) Str::uuid();
        $this->assertSame('quarantined', $service->receive($route, json_encode($message, JSON_THROW_ON_ERROR)));
        $message['device_id'] = $device;
        DB::table('devices')->where('id', $device)->update(['status' => 'registered']);
        $this->assertSame('quarantined', $service->receive($route, json_encode($message, JSON_THROW_ON_ERROR)));
        $this->assertDatabaseCount('telemetry_inbox', 0);
        $this->assertDatabaseHas('telemetry_quarantine', ['reason' => 'invalid_envelope']);
        $this->assertDatabaseHas('telemetry_quarantine', ['reason' => 'unprovisioned_device']);
    }
}
