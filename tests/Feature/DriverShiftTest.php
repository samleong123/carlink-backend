<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\DriverShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DriverShiftTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['nfc.hmac_key' => str_repeat('K', 40), 'nfc.hmac_key_version' => '1']);
        $user = User::create(['email_normalized' => 'manager@example.test', 'display_name' => 'Manager']);
        $org = Organization::create(['name' => 'Fleet', 'slug' => 'fleet']);
        Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $driver = (string) Str::uuid(); $vehicle = (string) Str::uuid(); $device = (string) Str::uuid(); $installation = (string) Str::uuid();
        DB::table('drivers')->insert(['id' => $driver, 'organization_id' => $org->id, 'name' => 'Driver A', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vehicles')->insert(['id' => $vehicle, 'organization_id' => $org->id, 'registration_number' => 'ABC123', 'registration_normalized' => 'ABC123', 'registration_country' => 'MY', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('devices')->insert(['id' => $device, 'organization_id' => $org->id, 'serial_number' => 'pi-a', 'status' => 'active', 'broker_principal' => 'device-a', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('device_installations')->insert(['id' => $installation, 'organization_id' => $org->id, 'vehicle_id' => $vehicle, 'device_id' => $device, 'effective_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        return [$user, $org, $driver, $vehicle, $device, $installation];
    }

    private function event(string $installation, string $fingerprint, ?string $time = null): array
    {
        return ['event_id' => (string) Str::uuid(), 'installation_id' => $installation, 'local_session_id' => (string) Str::uuid(), 'credential_type' => 'nfc_uid_sha256', 'credential_fingerprint' => $fingerprint, 'occurred_at' => $time ?? now()->addSecond()->toIso8601String()];
    }

    public function test_credential_enrolment_is_tenant_scoped_and_does_not_expose_fingerprint(): void
    {
        [$owner, $org, $driver] = $this->fixture();
        $path = '/api/v1/organizations/'.$org->id.'/drivers/'.$driver.'/credentials';
        $fingerprint = str_repeat('a', 64);
        $response = $this->actingAs($owner)->postJson($path, ['credential_fingerprint' => $fingerprint, 'credential_type' => 'nfc_uid_sha256'])->assertCreated();
        $this->assertStringNotContainsString($fingerprint, $response->getContent());
        $stored = DB::table('driver_credentials')->first();
        $this->assertNotSame($fingerprint, $stored->credential_hmac);
        $this->assertDatabaseHas('driver_credential_events', ['credential_id' => $stored->id, 'event' => 'enrolled']);
        $this->postJson($path, ['credential_fingerprint' => $fingerprint, 'credential_type' => 'nfc_uid_sha256'])->assertStatus(409);
        $this->postJson($path.'/'.$stored->id.'/revoke')->assertOk();
        $this->assertDatabaseHas('driver_credential_events', ['credential_id' => $stored->id, 'event' => 'revoked']);
    }

    public function test_authenticated_event_replay_handover_and_revoked_card(): void
    {
        [$owner, $org, $driver, $vehicle, $device, $installation] = $this->fixture();
        $driver2 = (string) Str::uuid();
        DB::table('drivers')->insert(['id' => $driver2, 'organization_id' => $org->id, 'name' => 'Driver B', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $path = '/api/v1/organizations/'.$org->id.'/drivers/';
        $this->actingAs($owner)->postJson($path.$driver.'/credentials', ['credential_fingerprint' => str_repeat('a', 64), 'credential_type' => 'nfc_uid_sha256'])->assertCreated();
        $second = $this->postJson($path.$driver2.'/credentials', ['credential_fingerprint' => str_repeat('b', 64), 'credential_type' => 'nfc_uid_sha256'])->assertCreated();
        $service = app(DriverShiftService::class);
        $event = $this->event($installation, str_repeat('a', 64));
        $first = $service->acceptAuthenticatedEvent($org->id, $device, $event);
        $this->assertSame('accepted', $first['decision']);
        $this->assertSame($first, $service->acceptAuthenticatedEvent($org->id, $device, $event));
        $this->assertDatabaseCount('driver_shifts', 1);
        $secondEvent = $this->event($installation, str_repeat('b', 64), now()->addSeconds(2)->toIso8601String());
        $next = $service->acceptAuthenticatedEvent($org->id, $device, $secondEvent);
        $this->assertSame('accepted', $next['decision']);
        $this->assertDatabaseHas('driver_shifts', ['id' => $first['shift_id'], 'state' => 'closed', 'closure_reason' => 'handover']);
        $this->assertDatabaseHas('driver_shifts', ['id' => $next['shift_id'], 'driver_id' => $driver2, 'state' => 'active']);
        $credential = $second->json('data.credential.id');
        $this->postJson($path.$driver2.'/credentials/'.$credential.'/revoke')->assertOk();
        $result = $service->acceptAuthenticatedEvent($org->id, $device, $this->event($installation, str_repeat('b', 64), now()->addSeconds(3)->toIso8601String()));
        $this->assertSame('rejected_unknown_credential', $result['decision']);
        $this->assertDatabaseHas('driver_shifts', ['id' => $next['shift_id'], 'state' => 'closed', 'closure_reason' => 'credential_revoked']);
    }

    public function test_late_event_does_not_change_current_shift_and_close_requires_manager(): void
    {
        [$owner, $org, $driver, $vehicle, $device, $installation] = $this->fixture();
        $this->actingAs($owner)->postJson('/api/v1/organizations/'.$org->id.'/drivers/'.$driver.'/credentials', ['credential_fingerprint' => str_repeat('a', 64), 'credential_type' => 'nfc_uid_sha256'])->assertCreated();
        $service = app(DriverShiftService::class);
        $active = $service->acceptAuthenticatedEvent($org->id, $device, $this->event($installation, str_repeat('a', 64)));
        $late = $service->acceptAuthenticatedEvent($org->id, $device, $this->event($installation, str_repeat('a', 64), now()->subHour()->toIso8601String()));
        $this->assertSame('requires_review', $late['decision']);
        $this->postJson('/api/v1/organizations/'.$org->id.'/driver-shifts/'.$active['shift_id'].'/close', ['reason' => 'Correction'])->assertOk();
        $this->assertDatabaseHas('driver_shift_events', ['shift_id' => $active['shift_id'], 'reason' => 'Correction']);
    }

    public function test_credential_key_change_fails_closed_and_repeated_taps_are_debounced(): void
    {
        [$owner, $org, $driver, $vehicle, $device, $installation] = $this->fixture();
        $path = '/api/v1/organizations/'.$org->id.'/drivers/'.$driver.'/credentials';
        $this->actingAs($owner)->postJson($path, ['credential_fingerprint' => str_repeat('a', 64), 'credential_type' => 'nfc_uid_sha256'])->assertCreated();
        $service = app(DriverShiftService::class);
        $first = $service->acceptAuthenticatedEvent($org->id, $device, $this->event($installation, str_repeat('a', 64)));
        $repeat = $service->acceptAuthenticatedEvent($org->id, $device, $this->event($installation, str_repeat('a', 64), now()->addSeconds(2)->toIso8601String()));
        $this->assertSame($first['shift_id'], $repeat['shift_id']);
        $this->assertDatabaseHas('driver_shifts', ['id' => $first['shift_id'], 'version' => 1]);
        config(['nfc.hmac_key' => str_repeat('X', 40)]);
        $this->postJson($path, ['credential_fingerprint' => str_repeat('b', 64), 'credential_type' => 'nfc_uid_sha256'])->assertStatus(503);
        $this->expectException(\RuntimeException::class);
        $service->acceptAuthenticatedEvent($org->id, $device, $this->event($installation, str_repeat('a', 64)));
    }

    public function test_expired_shift_closes_at_expiry(): void
    {
        [$owner, $org, $driver, $vehicle, $device, $installation] = $this->fixture();
        $this->actingAs($owner)->postJson('/api/v1/organizations/'.$org->id.'/drivers/'.$driver.'/credentials', ['credential_fingerprint' => str_repeat('a', 64), 'credential_type' => 'nfc_uid_sha256'])->assertCreated();
        $service = app(DriverShiftService::class);
        $result = $service->acceptAuthenticatedEvent($org->id, $device, $this->event($installation, str_repeat('a', 64)));
        $this->travel(17)->hours();
        $this->artisan('driver-shifts:expire')->assertExitCode(0);
        $row = DB::table('driver_shifts')->where('id', $result['shift_id'])->first();
        $this->assertSame('closed', $row->state);
        $this->assertSame('attribution_expired', $row->closure_reason);
        $this->assertSame($row->attribution_expires_at, $row->ended_at);
    }

    public function test_early_historical_event_cannot_overlap_a_closed_shift(): void
    {
        [$owner, $org, $driver, $vehicle, $device, $installation] = $this->fixture();
        $this->actingAs($owner)->postJson('/api/v1/organizations/'.$org->id.'/drivers/'.$driver.'/credentials', ['credential_fingerprint' => str_repeat('a', 64), 'credential_type' => 'nfc_uid_sha256'])->assertCreated();
        $service = app(DriverShiftService::class);
        $active = $service->acceptAuthenticatedEvent($org->id, $device, $this->event($installation, str_repeat('a', 64)));
        $this->postJson('/api/v1/organizations/'.$org->id.'/driver-shifts/'.$active['shift_id'].'/close', ['reason' => 'Correction'])->assertOk();
        $late = $service->acceptAuthenticatedEvent($org->id, $device, $this->event($installation, str_repeat('a', 64), now()->toIso8601String()));
        $this->assertSame('requires_review', $late['decision']);
        $this->assertDatabaseCount('driver_shifts', 1);
    }

    public function test_driver_cannot_enrol_credentials_or_close_a_shift(): void
    {
        [$owner, $org, $driver] = $this->fixture();
        Membership::where('organization_id', $org->id)->update(['role' => 'driver']);
        $this->actingAs($owner)->postJson('/api/v1/organizations/'.$org->id.'/drivers/'.$driver.'/credentials', ['credential_fingerprint' => str_repeat('a', 64), 'credential_type' => 'nfc_uid_sha256'])->assertForbidden();
        $this->postJson('/api/v1/organizations/'.$org->id.'/driver-shifts/'.Str::uuid().'/close', ['reason' => 'No'])->assertForbidden();
    }

    public function test_suspending_a_driver_closes_active_attribution(): void
    {
        [$owner, $org, $driver, $vehicle, $device, $installation] = $this->fixture();
        $this->actingAs($owner)->postJson('/api/v1/organizations/'.$org->id.'/drivers/'.$driver.'/credentials', [
            'credential_fingerprint' => str_repeat('a', 64), 'credential_type' => 'nfc_uid_sha256',
        ])->assertCreated();
        $service = app(DriverShiftService::class);
        $shift = $service->acceptAuthenticatedEvent($org->id, $device, $this->event($installation, str_repeat('a', 64)));
        $this->patchJson('/api/v1/organizations/'.$org->id.'/drivers/'.$driver, ['version' => 1, 'status' => 'suspended'])->assertOk();
        $this->assertDatabaseHas('driver_shifts', ['id' => $shift['shift_id'], 'state' => 'closed', 'closure_reason' => 'driver_suspended']);
        $this->assertDatabaseHas('driver_shift_events', ['shift_id' => $shift['shift_id'], 'event' => 'driver_suspended']);
        $next = $service->acceptAuthenticatedEvent($org->id, $device, $this->event($installation, str_repeat('a', 64), now()->addSeconds(3)->toIso8601String()));
        $this->assertSame('rejected_driver_inactive', $next['decision']);
    }

    public function test_shift_attribution_is_bounded_by_a_scheduled_installation_end(): void
    {
        [$owner, $org, $driver, $vehicle, $device, $installation] = $this->fixture();
        $this->actingAs($owner)->postJson('/api/v1/organizations/'.$org->id.'/drivers/'.$driver.'/credentials', [
            'credential_fingerprint' => str_repeat('a', 64), 'credential_type' => 'nfc_uid_sha256',
        ])->assertCreated();
        $end = now()->addHour()->startOfSecond();
        $this->postJson('/api/v1/organizations/'.$org->id.'/devices/'.$device.'/installations/'.$installation.'/close', [
            'effective_to' => $end->toIso8601String(), 'reason' => 'Scheduled replacement',
        ])->assertOk();
        $service = app(DriverShiftService::class);
        $shift = $service->acceptAuthenticatedEvent($org->id, $device, $this->event($installation, str_repeat('a', 64)));
        $this->assertSame('accepted', $shift['decision']);
        $expiry = DB::table('driver_shifts')->where('id', $shift['shift_id'])->value('attribution_expires_at');
        $this->assertTrue($end->equalTo(\Carbon\CarbonImmutable::parse($expiry)));
        $service->acceptAuthenticatedEvent($org->id, $device, $this->event($installation, str_repeat('a', 64), now()->addSeconds(20)->toIso8601String()));
        $expiry = DB::table('driver_shifts')->where('id', $shift['shift_id'])->value('attribution_expires_at');
        $this->assertTrue($end->equalTo(\Carbon\CarbonImmutable::parse($expiry)));

        $this->travel(61)->minutes();
        $this->artisan('driver-shifts:expire')->assertExitCode(0);
        $late = $service->acceptAuthenticatedEvent($org->id, $device, $this->event($installation, str_repeat('a', 64), $end->subSeconds(30)->toIso8601String()));
        $this->assertSame('requires_review', $late['decision']);
        $this->assertDatabaseHas('driver_shifts', ['id' => $shift['shift_id'], 'state' => 'closed']);
    }

    public function test_installation_cannot_end_before_its_driver_shift_is_closed(): void
    {
        [$owner, $org, $driver, $vehicle, $device, $installation] = $this->fixture();
        $this->actingAs($owner)->postJson('/api/v1/organizations/'.$org->id.'/drivers/'.$driver.'/credentials', [
            'credential_fingerprint' => str_repeat('a', 64), 'credential_type' => 'nfc_uid_sha256',
        ])->assertCreated();
        $shift = app(DriverShiftService::class)->acceptAuthenticatedEvent($org->id, $device, $this->event($installation, str_repeat('a', 64)));
        $path = '/api/v1/organizations/'.$org->id.'/devices/'.$device.'/installations/'.$installation.'/close';
        $this->postJson($path, ['effective_to' => now()->addMinute()->toIso8601String(), 'reason' => 'Replacement'])->assertStatus(409);
        $this->assertNull(DB::table('device_installations')->where('id', $installation)->value('effective_to'));

        $this->postJson('/api/v1/organizations/'.$org->id.'/driver-shifts/'.$shift['shift_id'].'/close', ['reason' => 'Replacement'])->assertOk();
        $ended = \Carbon\CarbonImmutable::parse(DB::table('driver_shifts')->where('id', $shift['shift_id'])->value('ended_at'));
        $this->postJson($path, ['effective_to' => $ended->subSecond()->toIso8601String(), 'reason' => 'Replacement'])->assertStatus(409);
        $this->postJson($path, ['effective_to' => $ended->toIso8601String(), 'reason' => 'Replacement'])->assertOk();
    }

    public function test_listing_driver_credentials_returns_safe_metadata_and_requires_permission(): void
    {
        [$owner, $org, $driver] = $this->fixture();
        $path = '/api/v1/organizations/'.$org->id.'/drivers/'.$driver.'/credentials';
        $fingerprint = str_repeat('a', 64);
        $enrolled = $this->actingAs($owner)->postJson($path, [
            'credential_fingerprint' => $fingerprint,
            'credential_type' => 'nfc_uid_sha256',
        ])->assertCreated();

        $response = $this->actingAs($owner)->getJson($path)->assertOk();
        $response->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $enrolled->json('data.credential.id'))
            ->assertJsonPath('data.items.0.driver_id', $driver)
            ->assertJsonPath('data.items.0.type', 'nfc_uid_sha256')
            ->assertJsonPath('data.items.0.assurance', 'uid_low');
        $item = $response->json('data.items.0');
        $this->assertArrayNotHasKey('credential_hmac', $item);
        $this->assertArrayNotHasKey('hash_key_version', $item);
        $this->assertStringNotContainsString($fingerprint, $response->getContent());

        $this->getJson('/api/v1/organizations/'.$org->id.'/drivers/'.Str::uuid().'/credentials')->assertNotFound();

        Membership::where('organization_id', $org->id)->update(['role' => 'driver']);
        $this->actingAs($owner)->getJson($path)->assertForbidden();
    }

    public function test_device_driver_auth_endpoint_validates_token_payload_and_splits_automatic_journey(): void
    {
        [$owner, $org, $driver, $vehicle, $device] = $this->fixture();
        $driver2 = (string) Str::uuid();
        DB::table('drivers')->insert(['id' => $driver2, 'organization_id' => $org->id, 'name' => 'Driver B', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $token = str_repeat('t', 64);
        DB::table('devices')->where('id', $device)->update(['auth_token_encrypted' => Crypt::encryptString($token)]);
        $fingerprint1 = str_repeat('a', 64);
        $fingerprint2 = str_repeat('b', 64);
        $this->actingAs($owner)->postJson('/api/v1/organizations/'.$org->id.'/drivers/'.$driver.'/credentials', [
            'credential_fingerprint' => $fingerprint1,
            'credential_type' => 'nfc_uid_sha256',
        ])->assertCreated();
        $this->actingAs($owner)->postJson('/api/v1/organizations/'.$org->id.'/drivers/'.$driver2.'/credentials', [
            'credential_fingerprint' => $fingerprint2,
            'credential_type' => 'nfc_uid_sha256',
        ])->assertCreated();

        $endpoint = '/api/v1/device/'.$device.'/driver-auth';
        $headers = ['Authorization' => 'Bearer '.$token];
        $localSession = (string) Str::uuid();

        $this->postJson($endpoint, [
            'event_id' => (string) Str::uuid(),
            'local_session_id' => $localSession,
            'credential_type' => 'nfc_uid_sha256',
            'credential_fingerprint' => $fingerprint1,
            'occurred_at' => now()->toIso8601String(),
        ])->assertUnauthorized();

        $this->postJson($endpoint, [
            'event_id' => (string) Str::uuid(),
            'local_session_id' => $localSession,
            'credential_type' => 'nfc_uid_sha256',
            'credential_fingerprint' => $fingerprint1,
            'occurred_at' => now()->toIso8601String(),
            'installation_id' => (string) Str::uuid(),
        ], $headers)->assertUnprocessable();

        $unknown = $this->postJson($endpoint, [
            'event_id' => (string) Str::uuid(),
            'local_session_id' => $localSession,
            'credential_type' => 'nfc_uid_sha256',
            'credential_fingerprint' => str_repeat('f', 64),
            'occurred_at' => now()->toIso8601String(),
        ], $headers)->assertOk();
        $unknown->assertJsonPath('data.decision', 'rejected_unknown_credential')
            ->assertJsonPath('data.shift_id', null);

        $obsId = (string) Str::uuid();
        $autoJourneyId = (string) Str::uuid();
        $lastObserved = CarbonImmutable::now('UTC')->subSeconds(10)->startOfSecond();
        DB::table('journeys')->insert([
            'id' => $autoJourneyId,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle,
            'state' => 'active',
            'started_at' => $lastObserved->subSeconds(30),
            'last_observed_at' => $lastObserved,
            'start_observation_id' => $obsId,
            'unknown_seconds' => 0,
            'detection_version' => 'packet-snapshot-v1',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('journey_detection_states')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle,
            'state' => 'active',
            'current_journey_id' => $autoJourneyId,
            'last_observation_id' => $obsId,
            'last_observed_at' => $lastObserved,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tapAt = CarbonImmutable::now('UTC')->addSecond()->startOfSecond();
        $res = $this->postJson($endpoint, [
            'event_id' => (string) Str::uuid(),
            'local_session_id' => $localSession,
            'credential_type' => 'nfc_uid_sha256',
            'credential_fingerprint' => $fingerprint1,
            'occurred_at' => $tapAt->toIso8601String(),
        ], $headers)->assertOk();

        $res->assertJsonPath('data.decision', 'accepted')
            ->assertJsonPath('data.local_session_id', $localSession)
            ->assertJsonPath('data.driver_id', $driver)
            ->assertJsonPath('data.driver_display_name', 'Driver A');
        $this->assertNotNull($res->json('data.shift_id'));
        $this->assertNotNull($res->json('data.valid_until'));
        $newJourneyId = $res->json('data.active_journey_id');
        $this->assertNotNull($newJourneyId);
        $this->assertNotSame($autoJourneyId, $newJourneyId);
        $this->assertStringNotContainsString($fingerprint1, $res->getContent());

        $this->assertDatabaseHas('journeys', [
            'id' => $autoJourneyId,
            'state' => 'completed',
            'end_reason' => 'driver_authenticated',
            'end_observation_id' => $obsId,
            'revision' => 2,
        ]);
        $this->assertDatabaseHas('journeys', [
            'id' => $newJourneyId,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle,
            'state' => 'active',
            'start_observation_id' => $obsId,
            'detection_version' => 'driver-session-v1',
            'revision' => 1,
        ]);
        $jState = DB::table('journey_detection_states')->where('organization_id', $org->id)->where('vehicle_id', $vehicle)->first();
        $this->assertSame($newJourneyId, $jState->current_journey_id);
        $this->assertSame('active', $jState->state);
        $this->assertTrue($lastObserved->equalTo(CarbonImmutable::parse($jState->last_observed_at)->utc()));

        // Same-driver reauthentication and driver handover must not split the active journey again.
        $reauth = $this->postJson($endpoint, [
            'event_id' => (string) Str::uuid(),
            'local_session_id' => $localSession,
            'credential_type' => 'nfc_uid_sha256',
            'credential_fingerprint' => $fingerprint1,
            'occurred_at' => $tapAt->addSeconds(2)->toIso8601String(),
        ], $headers)->assertOk();
        $this->assertSame($res->json('data.shift_id'), $reauth->json('data.shift_id'));
        $this->assertSame($newJourneyId, $reauth->json('data.active_journey_id'));

        $handover = $this->postJson($endpoint, [
            'event_id' => (string) Str::uuid(),
            'local_session_id' => (string) Str::uuid(),
            'credential_type' => 'nfc_uid_sha256',
            'credential_fingerprint' => $fingerprint2,
            'occurred_at' => $tapAt->addSeconds(5)->toIso8601String(),
        ], $headers)->assertOk();
        $this->assertSame('accepted', $handover->json('data.decision'));
        $this->assertSame($newJourneyId, $handover->json('data.active_journey_id'));
        $this->assertDatabaseCount('journeys', 2);
    }

    public function test_active_nfc_shift_is_exposed_across_fleet_overview_and_shifts_until_closed(): void
    {
        [$owner, $org, $driver, $vehicle, $device, $installation] = $this->fixture();
        DB::table('vehicles')->where('id', $vehicle)->update(['make' => 'Toyota', 'model' => 'Hiace']);
        $base = '/api/v1/organizations/'.$org->id;
        $this->actingAs($owner)->postJson($base.'/drivers/'.$driver.'/credentials', [
            'credential_fingerprint' => str_repeat('a', 64),
            'credential_type' => 'nfc_uid_sha256',
        ])->assertCreated();

        $shift = app(DriverShiftService::class)->acceptAuthenticatedEvent(
            $org->id,
            $device,
            $this->event($installation, str_repeat('a', 64))
        );
        $this->assertSame('accepted', $shift['decision']);
        $shiftId = $shift['shift_id'];

        $fleetStates = $this->actingAs($owner)->getJson($base.'/fleet-states')->assertOk();
        $fleetStates->assertJsonPath('data.items.0.id', $vehicle)
            ->assertJsonPath('data.items.0.current_shift_id', $shiftId)
            ->assertJsonPath('data.items.0.current_driver_id', $driver)
            ->assertJsonPath('data.items.0.current_driver_name', 'Driver A');
        $this->assertNotNull($fleetStates->json('data.items.0.current_shift_started_at'));
        $this->assertNotNull($fleetStates->json('data.items.0.current_shift_expires_at'));

        $this->getJson($base.'/vehicles')->assertOk()
            ->assertJsonPath('data.items.0.current_shift_id', $shiftId)
            ->assertJsonPath('data.items.0.current_driver_id', $driver)
            ->assertJsonPath('data.items.0.current_driver_name', 'Driver A');

        $this->getJson($base.'/vehicles/'.$vehicle)->assertOk()
            ->assertJsonPath('data.item.current_shift_id', $shiftId)
            ->assertJsonPath('data.item.current_driver_id', $driver)
            ->assertJsonPath('data.item.current_driver_name', 'Driver A')
            ->assertJsonPath('data.active_shift.id', $shiftId)
            ->assertJsonPath('data.active_shift.driver_id', $driver)
            ->assertJsonPath('data.active_shift.driver_name', 'Driver A')
            ->assertJsonPath('data.active_shift.vehicle_id', $vehicle)
            ->assertJsonPath('data.active_shift.state', 'active');

        $this->getJson($base.'/drivers')->assertOk()
            ->assertJsonPath('data.items.0.current_shift_id', $shiftId)
            ->assertJsonPath('data.items.0.current_vehicle_id', $vehicle)
            ->assertJsonPath('data.items.0.current_vehicle_registration', 'ABC123')
            ->assertJsonPath('data.items.0.current_vehicle_make', 'Toyota')
            ->assertJsonPath('data.items.0.current_vehicle_model', 'Hiace');

        $this->getJson($base.'/drivers/'.$driver)->assertOk()
            ->assertJsonPath('data.item.current_shift_id', $shiftId)
            ->assertJsonPath('data.item.current_vehicle_id', $vehicle)
            ->assertJsonPath('data.item.current_vehicle_registration', 'ABC123')
            ->assertJsonPath('data.active_shift.id', $shiftId)
            ->assertJsonPath('data.active_shift.driver_id', $driver)
            ->assertJsonPath('data.active_shift.vehicle_id', $vehicle)
            ->assertJsonPath('data.active_shift.registration_number', 'ABC123')
            ->assertJsonPath('data.active_shift.make', 'Toyota')
            ->assertJsonPath('data.active_shift.model', 'Hiace')
            ->assertJsonPath('data.active_shift.state', 'active');

        $this->getJson($base.'/driver-shifts')->assertOk()
            ->assertJsonPath('data.shifts.0.id', $shiftId)
            ->assertJsonPath('data.shifts.0.driver_name', 'Driver A')
            ->assertJsonPath('data.shifts.0.registration_number', 'ABC123')
            ->assertJsonPath('data.shifts.0.vehicle_make', 'Toyota')
            ->assertJsonPath('data.shifts.0.vehicle_model', 'Hiace')
            ->assertJsonPath('data.shifts.0.state', 'active');

        $this->getJson($base.'/driver-shifts/'.$shiftId)->assertOk()
            ->assertJsonPath('data.shift.id', $shiftId)
            ->assertJsonPath('data.shift.driver_name', 'Driver A')
            ->assertJsonPath('data.shift.registration_number', 'ABC123')
            ->assertJsonPath('data.shift.vehicle_make', 'Toyota')
            ->assertJsonPath('data.shift.vehicle_model', 'Hiace')
            ->assertJsonPath('data.shift.state', 'active');

        // A user with dashboard/vehicle permissions but without shifts.read or drivers.read must not see driver shift info.
        $viewer = User::create(['email_normalized' => 'dash-only@example.test', 'display_name' => 'Dashboard Only']);
        Membership::create(['organization_id' => $org->id, 'user_id' => $viewer->id, 'role' => 'module_dashboard', 'status' => 'active']);
        $this->actingAs($viewer)->getJson($base.'/fleet-states')->assertOk()
            ->assertJsonPath('data.items.0.current_shift_id', null)
            ->assertJsonPath('data.items.0.current_driver_id', null)
            ->assertJsonPath('data.items.0.current_driver_name', null);
        $this->getJson($base.'/vehicles')->assertOk()
            ->assertJsonPath('data.items.0.current_shift_id', null)
            ->assertJsonPath('data.items.0.current_driver_id', null)
            ->assertJsonPath('data.items.0.current_driver_name', null);
        $this->getJson($base.'/vehicles/'.$vehicle)->assertOk()
            ->assertJsonPath('data.item.current_driver_name', null)
            ->assertJsonPath('data.active_shift', null);

        $this->actingAs($owner)->postJson($base.'/driver-shifts/'.$shiftId.'/close', ['reason' => 'Shift ended'])->assertOk();

        $this->getJson($base.'/fleet-states')->assertOk()
            ->assertJsonPath('data.items.0.current_shift_id', null)
            ->assertJsonPath('data.items.0.current_driver_id', null)
            ->assertJsonPath('data.items.0.current_driver_name', null);
        $this->getJson($base.'/vehicles')->assertOk()
            ->assertJsonPath('data.items.0.current_shift_id', null)
            ->assertJsonPath('data.items.0.current_driver_id', null)
            ->assertJsonPath('data.items.0.current_driver_name', null);
        $this->getJson($base.'/vehicles/'.$vehicle)->assertOk()
            ->assertJsonPath('data.item.current_driver_name', null)
            ->assertJsonPath('data.active_shift', null);
        $this->getJson($base.'/drivers')->assertOk()
            ->assertJsonPath('data.items.0.current_shift_id', null)
            ->assertJsonPath('data.items.0.current_vehicle_id', null)
            ->assertJsonPath('data.items.0.current_vehicle_registration', null);
        $this->getJson($base.'/drivers/'.$driver)->assertOk()
            ->assertJsonPath('data.item.current_vehicle_registration', null)
            ->assertJsonPath('data.active_shift', null);
    }

    public function test_nfc_toggle_tap_signs_out_driver_and_closes_active_journey(): void
    {
        [$owner, $org, $driver, $vehicle, $device] = $this->fixture();
        $token = str_repeat('t', 64);
        DB::table('devices')->where('id', $device)->update(['auth_token_encrypted' => Crypt::encryptString($token)]);
        $fingerprint = str_repeat('a', 64);
        $this->actingAs($owner)->postJson('/api/v1/organizations/'.$org->id.'/drivers/'.$driver.'/credentials', [
            'credential_fingerprint' => $fingerprint,
            'credential_type' => 'nfc_uid_sha256',
        ])->assertCreated();

        $endpoint = '/api/v1/device/'.$device.'/driver-auth';
        $headers = ['Authorization' => 'Bearer '.$token];
        $tap1At = CarbonImmutable::now('UTC')->subSeconds(10)->startOfSecond();

        $first = $this->postJson($endpoint, [
            'event_id' => (string) Str::uuid(),
            'local_session_id' => (string) Str::uuid(),
            'credential_type' => 'nfc_uid_sha256',
            'credential_fingerprint' => $fingerprint,
            'occurred_at' => $tap1At->toIso8601String(),
            'action' => 'toggle',
        ], $headers)->assertOk();
        $first->assertJsonPath('data.decision', 'accepted');
        $shiftId = $first->json('data.shift_id');
        $this->assertNotNull($shiftId);
        $this->assertNotNull($first->json('data.valid_until'));

        // Simulate an active journey started during this driver shift.
        $journeyId = (string) Str::uuid();
        $obsId = (string) Str::uuid();
        DB::table('journeys')->insert([
            'id' => $journeyId,
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle,
            'state' => 'active',
            'started_at' => $tap1At->addSecond(),
            'last_observed_at' => $tap1At->addSeconds(4),
            'start_observation_id' => $obsId,
            'unknown_seconds' => 0,
            'detection_version' => 'packet-snapshot-v1',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('journey_detection_states')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle,
            'state' => 'active',
            'current_journey_id' => $journeyId,
            'last_observation_id' => $obsId,
            'last_observed_at' => $tap1At->addSeconds(4),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Second tap by the same driver with action=toggle signs out and completes the active journey.
        $tap2At = $tap1At->addSeconds(6);
        $second = $this->postJson($endpoint, [
            'event_id' => (string) Str::uuid(),
            'local_session_id' => (string) Str::uuid(),
            'credential_type' => 'nfc_uid_sha256',
            'credential_fingerprint' => $fingerprint,
            'occurred_at' => $tap2At->toIso8601String(),
            'action' => 'toggle',
        ], $headers)->assertOk();
        $second->assertJsonPath('data.decision', 'signed_out')
            ->assertJsonPath('data.shift_id', $shiftId)
            ->assertJsonPath('data.valid_until', null)
            ->assertJsonPath('data.active_journey_id', null);

        $this->assertDatabaseHas('driver_shifts', [
            'id' => $shiftId,
            'state' => 'closed',
            'closure_reason' => 'driver_tap_out',
        ]);
        $this->assertDatabaseHas('journeys', [
            'id' => $journeyId,
            'state' => 'completed',
            'end_reason' => 'driver_signed_out',
        ]);
        $this->assertDatabaseHas('journey_detection_states', [
            'organization_id' => $org->id,
            'vehicle_id' => $vehicle,
            'state' => 'stationary',
            'current_journey_id' => null,
        ]);

        // Third tap signs back in with a new shift.
        $third = $this->postJson($endpoint, [
            'event_id' => (string) Str::uuid(),
            'local_session_id' => (string) Str::uuid(),
            'credential_type' => 'nfc_uid_sha256',
            'credential_fingerprint' => $fingerprint,
            'occurred_at' => $tap2At->addSeconds(5)->toIso8601String(),
            'action' => 'toggle',
        ], $headers)->assertOk();
        $third->assertJsonPath('data.decision', 'accepted');
        $this->assertNotSame($shiftId, $third->json('data.shift_id'));
    }
}
