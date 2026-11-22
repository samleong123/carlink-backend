<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Journeys\JourneyDetector;
use App\Services\Mileage\SpeedLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class JourneyDetectionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::create(['email_normalized' => 'journeys@example.test', 'display_name' => 'Owner']);
        $org = Organization::create(['name' => 'Journeys Fleet', 'slug' => 'journeys']);
        Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $vehicle = (string) Str::uuid(); $device = (string) Str::uuid(); $installation = (string) Str::uuid(); $boot = (string) Str::uuid();
        $start = CarbonImmutable::now('UTC')->subHours(2)->startOfMinute();
        DB::table('vehicles')->insert(['id' => $vehicle, 'organization_id' => $org->id, 'registration_number' => 'JRN1', 'registration_normalized' => 'JRN1', 'registration_country' => 'MY', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('devices')->insert(['id' => $device, 'organization_id' => $org->id, 'serial_number' => 'journey-pi', 'status' => 'active', 'broker_principal' => 'journey-pi', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('device_installations')->insert(['id' => $installation, 'organization_id' => $org->id, 'device_id' => $device, 'vehicle_id' => $vehicle, 'effective_from' => $start->subHour(), 'created_at' => now(), 'updated_at' => now()]);
        return [$user, $org, $vehicle, $device, $installation, $boot, $start];
    }

    private function observe(array $f, int $milliseconds, float $speed, string $engine = 'unknown'): string
    {
        [, $org, , $device, $installation, $boot, $start] = $f;
        $at = $start->addMilliseconds($milliseconds); $stamp = $at->format('Y-m-d\TH:i:s.v\Z');
        $inbox = (string) Str::uuid(); $eventId = (string) Str::uuid(); $sample = (string) Str::uuid();
        $payload = ['speed_kmh' => ['value' => $speed, 'pid' => '010D', 'quality' => 'valid', 'sample_id' => $sample, 'observed_at' => $stamp, 'observed_uptime_ms' => $milliseconds]];
        if ($engine !== 'unknown') $payload['engine_state'] = ['value' => $engine, 'quality' => 'valid', 'observed_at' => $stamp];
        $event = ['occurred_at' => $stamp, 'uptime_ms' => $milliseconds, 'clock' => ['synchronized' => true, 'estimated_error_ms' => 100], 'payload' => ['vehicle' => $payload]];
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        DB::table('telemetry_inbox')->insert(['id' => $inbox, 'organization_id' => $org->id, 'device_id' => $device, 'installation_id' => $installation,
            'event_id' => $eventId, 'boot_id' => $boot, 'sequence' => $milliseconds, 'occurred_at' => $at, 'received_at' => now(), 'payload_sha256' => hash('sha256', $raw), 'raw_payload' => $raw, 'state' => 'pending_projection']);
        app(SpeedLedger::class)->project($inbox);
        return DB::table('mileage_speed_observations')->where('inbox_id', $inbox)->value('id');
    }

    public function test_journey_starts_after_sustained_movement_and_ends_after_engine_off_stop(): void
    {
        $f = $this->fixture(); $detector = app(JourneyDetector::class);
        $first = $this->observe($f, 0, 20); $detector->project($first);
        $detector->project($this->observe($f, 5000, 20));
        $this->assertDatabaseCount('journeys', 0);
        $detector->project($this->observe($f, 10000, 20));
        $this->assertSame('duplicate', $detector->project($first));
        $journey = DB::table('journeys')->first();
        $this->assertSame('active', $journey->state);
        $this->assertTrue($f[6]->equalTo(CarbonImmutable::parse($journey->started_at)));
        for ($i = 0; $i <= 60; $i++) $detector->project($this->observe($f, 15000 + $i * 5000, 0, 'off'));
        $journey = DB::table('journeys')->first();
        $this->assertSame('completed', $journey->state);
        $this->assertSame('stationary_engine_off', $journey->end_reason);
    }

    public function test_unknown_gap_never_implies_stop_and_late_data_requires_rebuild(): void
    {
        $f = $this->fixture(); $detector = app(JourneyDetector::class);
        foreach ([0, 5000, 10000] as $ms) $detector->project($this->observe($f, $ms, 20));
        $detector->project($this->observe($f, 40000, 0));
        $journey = DB::table('journeys')->first();
        $this->assertSame('active', $journey->state);
        $this->assertSame(30, (int) $journey->unknown_seconds);
        $this->assertSame('requires_rebuild', $detector->project($this->observe($f, 7500, 20)));
        $this->assertSame('active', DB::table('journeys')->first()->state);
        $this->artisan('journeys:mark-gaps')->assertExitCode(0);
        $marked = DB::table('journeys')->first();
        $this->assertSame('incomplete', $marked->state);
        $this->assertSame('automatic_end', $marked->end_reason);
        $this->assertSame('requires_rebuild', $detector->project($this->observe($f, 60000, 20)));
        $this->assertSame(1, DB::table('journeys')->count());
    }

    public function test_running_engine_does_not_end_a_journey_and_unknown_engine_requires_fifteen_minutes(): void
    {
        $f = $this->fixture(); $detector = app(JourneyDetector::class);
        foreach ([0, 5000, 10000] as $ms) $detector->project($this->observe($f, $ms, 20));
        for ($i = 0; $i <= 36; $i++) $detector->project($this->observe($f, 20000 + $i * 10000, 0, 'running'));
        $this->assertSame('active', DB::table('journeys')->first()->state);
        for ($i = 0; $i < 90; $i++) $detector->project($this->observe($f, 390000 + $i * 10000, 0));
        $this->assertSame('active', DB::table('journeys')->first()->state);
        $detector->project($this->observe($f, 1290000, 0));
        $this->assertSame('completed', DB::table('journeys')->first()->state);
        $this->assertSame('stationary_engine_unknown', DB::table('journeys')->first()->end_reason);
    }

    public function test_journey_reads_are_tenant_scoped_and_do_not_expose_shared_journeys_to_drivers(): void
    {
        $f = $this->fixture(); [$user, $org, $vehicle] = $f; $detector = app(JourneyDetector::class);
        foreach ([0, 5000, 10000] as $ms) $detector->project($this->observe($f, $ms, 20));
        $base = '/api/v1/organizations/'.$org->id.'/journeys';
        $response = $this->actingAs($user)->getJson($base)->assertOk()
            ->assertJsonPath('data.items.0.vehicle_id', $vehicle)
            ->assertJsonPath('data.items.0.driver_attribution', 'not_implemented')
            ->assertJsonPath('data.items.0.distance_quality', 'speed_trapezoid');
        $this->assertGreaterThan(0, $response->json('data.items.0.distance_m'));
        $this->assertGreaterThan(0, $response->json('data.items.0.distance_km'));
        $this->getJson($base.'?driver_id='.Str::uuid())->assertStatus(422);
        $driver = User::create(['email_normalized' => 'journey-driver@example.test', 'display_name' => 'Driver']);
        Membership::create(['organization_id' => $org->id, 'user_id' => $driver->id, 'role' => 'driver', 'status' => 'active']);
        $this->actingAs($driver)->getJson($base)->assertForbidden();
        $this->actingAs($driver)->postJson($base.'/start', [])->assertNotFound();
    }

    public function test_automatic_end_triggers_at_one_hour_and_not_thirty_minutes(): void
    {
        $f = $this->fixture(); $detector = app(JourneyDetector::class);
        foreach ([0, 5000, 10000] as $ms) $detector->project($this->observe($f, $ms, 20));

        // 30-minute gap (1,800,000 ms) keeps the journey active
        $detector->project($this->observe($f, 1810000, 20));
        $journey = DB::table('journeys')->first();
        $this->assertSame('active', $journey->state);
        $this->assertNull($journey->end_reason);

        // 60-minute gap (3,600,000 ms) ends the journey automatically
        $detector->project($this->observe($f, 5410000, 0));
        $journey = DB::table('journeys')->where('id', $journey->id)->first();
        $this->assertSame('incomplete', $journey->state);
        $this->assertSame('automatic_end', $journey->end_reason);
    }

    public function test_journey_representation_maps_legacy_end_reason_and_computes_fuel_efficiency(): void
    {
        $f = $this->fixture();
        [$user, $org, $vehicle, $device, $installation, , $start] = $f;
        $journeyId = (string) Str::uuid();
        $from = $start;
        $to = $start->addSeconds(3600);

        DB::table('journeys')->insert([
            'id' => $journeyId,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle,
            'state' => 'incomplete',
            'started_at' => $from,
            'ended_at' => $to,
            'last_observed_at' => $to,
            'start_observation_id' => (string) Str::uuid(),
            'end_reason' => 'telemetry_gap',
            'unknown_seconds' => 0,
            'detection_version' => 'pilot-v1',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert two telemetry_samples 1 hour apart (60 km/h => 60 km, 6 L/h => 6 L => 10.0 L/100km / 10.0 km/L)
        foreach ([0 => $from, 1 => $to] as $idx => $at) {
            $inboxId = (string) Str::uuid();
            $raw = json_encode(['sample' => $idx], JSON_THROW_ON_ERROR);
            DB::table('telemetry_inbox')->insert([
                'id' => $inboxId,
                'organization_id' => $org->id,
                'device_id' => $device,
                'installation_id' => $installation,
                'event_id' => (string) Str::uuid(),
                'boot_id' => null,
                'sequence' => null,
                'occurred_at' => $at,
                'received_at' => now(),
                'payload_sha256' => hash('sha256', $raw),
                'raw_payload' => $raw,
                'state' => 'projected',
            ]);
            DB::table('telemetry_samples')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'vehicle_id' => $vehicle,
                'installation_id' => $installation,
                'inbox_id' => $inboxId,
                'occurred_at' => $at,
                'received_at' => now(),
                'speed_kmh' => 60.0,
                'fuel_rate_lph' => 6.0,
                'quality' => json_encode(['speed' => 'valid']),
                'created_at' => now(),
            ]);
        }

        $this->actingAs($user)
            ->getJson('/api/v1/organizations/'.$org->id.'/journeys/'.$journeyId)
            ->assertOk()
            ->assertJsonPath('data.journey.end_reason', 'automatic_end')
            ->assertJsonPath('data.journey.distance_quality', 'speed_estimated')
            ->assertJsonPath('data.journey.distance_m', 60000)
            ->assertJsonPath('data.journey.distance_km', 60)
            ->assertJsonPath('data.journey.fuel_litres', 6)
            ->assertJsonPath('data.journey.fuel_efficiency_l_per_100km', 10)
            ->assertJsonPath('data.journey.fuel_efficiency_km_per_l', 10)
            ->assertJsonPath('data.journey.fuel_efficiency', '10 L/100km / 10 km/L');

        // Also verify GPS Haversine fallback and maf_gps fuel extraction
        $gpsJourneyId = (string) Str::uuid();
        $gpsFrom = $start->addHours(2);
        $gpsTo = $gpsFrom->addSeconds(1800);
        DB::table('journeys')->insert([
            'id' => $gpsJourneyId,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle,
            'state' => 'completed',
            'started_at' => $gpsFrom,
            'ended_at' => $gpsTo,
            'last_observed_at' => $gpsTo,
            'start_observation_id' => (string) Str::uuid(),
            'end_reason' => 'stationary_engine_off',
            'unknown_seconds' => 0,
            'detection_version' => 'pilot-v1',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $coords = [
            [$gpsFrom, 3.1390, 101.6869],
            [$gpsTo, 3.2390, 101.6869],
        ];
        foreach ($coords as $idx => [$at, $lat, $lon]) {
            $inboxId = (string) Str::uuid();
            $raw = json_encode(['gps' => $idx], JSON_THROW_ON_ERROR);
            DB::table('telemetry_inbox')->insert([
                'id' => $inboxId,
                'organization_id' => $org->id,
                'device_id' => $device,
                'installation_id' => $installation,
                'event_id' => (string) Str::uuid(),
                'boot_id' => null,
                'sequence' => null,
                'occurred_at' => $at,
                'received_at' => now(),
                'payload_sha256' => hash('sha256', $raw),
                'raw_payload' => $raw,
                'state' => 'projected',
            ]);
            DB::table('telemetry_samples')->insert([
                'id' => (string) Str::uuid(),
                'organization_id' => $org->id,
                'vehicle_id' => $vehicle,
                'installation_id' => $installation,
                'inbox_id' => $inboxId,
                'occurred_at' => $at,
                'received_at' => now(),
                'speed_kmh' => null,
                'latitude' => $lat,
                'longitude' => $lon,
                'fuel_rate_lph' => null,
                'vehicle_data' => json_encode(['maf_gps' => 10.0], JSON_THROW_ON_ERROR),
                'quality' => json_encode(['speed' => 'unavailable']),
                'created_at' => now(),
            ]);
        }

        $gpsRes = $this->actingAs($user)
            ->getJson('/api/v1/organizations/'.$org->id.'/journeys/'.$gpsJourneyId)
            ->assertOk()
            ->assertJsonPath('data.journey.distance_quality', 'gps_coordinates');
        $this->assertGreaterThan(10000, $gpsRes->json('data.journey.distance_m'));
        $this->assertGreaterThan(0, $gpsRes->json('data.journey.fuel_litres'));
        $this->assertNotNull($gpsRes->json('data.journey.fuel_efficiency'));
    }
}
