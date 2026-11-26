<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\PrivateAssets;
use App\Services\Telemetry\TelemetryInbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class IncidentManagementTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(string $slug = 'incfleet'): array
    {
        config(['broker.verified_topic_acls' => true]);

        $org = Organization::create(['name' => ucfirst($slug) . ' Fleet', 'slug' => $slug]);
        $owner = User::create(['email_normalized' => "{$slug}-owner@example.test", 'display_name' => 'Fleet Owner']);
        $manager = User::create(['email_normalized' => "{$slug}-mgr@example.test", 'display_name' => 'Fleet Manager']);
        $viewer = User::create(['email_normalized' => "{$slug}-viewer@example.test", 'display_name' => 'Fleet Viewer']);
        $driverUser = User::create(['email_normalized' => "{$slug}-drv@example.test", 'display_name' => 'Road Driver']);

        Membership::create(['organization_id' => $org->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        Membership::create(['organization_id' => $org->id, 'user_id' => $manager->id, 'role' => 'manager', 'status' => 'active']);
        Membership::create(['organization_id' => $org->id, 'user_id' => $viewer->id, 'role' => 'viewer', 'status' => 'active']);
        Membership::create(['organization_id' => $org->id, 'user_id' => $driverUser->id, 'role' => 'driver', 'status' => 'active']);

        $vehicleId = (string) Str::uuid();
        $deviceId = (string) Str::uuid();
        $installationId = (string) Str::uuid();
        $driverId = (string) Str::uuid();
        $start = CarbonImmutable::now('UTC')->subMinutes(30)->startOfMinute();

        DB::table('vehicles')->insert([
            'id' => $vehicleId,
            'organization_id' => $org->id,
            'registration_number' => 'VEH-001',
            'registration_normalized' => 'VEH001',
            'registration_country' => 'MY',
            'make' => 'Toyota',
            'model' => 'Hilux',
            'fuel_type' => 'petrol',
            'status' => 'active',
            'version' => 1,
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
            'id' => $installationId,
            'organization_id' => $org->id,
            'device_id' => $deviceId,
            'vehicle_id' => $vehicleId,
            'effective_from' => $start->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('drivers')->insert([
            'id' => $driverId,
            'organization_id' => $org->id,
            'user_id' => $driverUser->id,
            'name' => 'Road Driver',
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'org' => $org,
            'owner' => $owner,
            'manager' => $manager,
            'viewer' => $viewer,
            'driverUser' => $driverUser,
            'driverId' => $driverId,
            'vehicleId' => $vehicleId,
            'deviceId' => $deviceId,
            'installationId' => $installationId,
            'start' => $start,
            'base' => "/api/v1/organizations/{$org->id}",
        ];
    }

    private function sendPiPacket(array $f, CarbonImmutable $at, array $overrides = []): void
    {
        $payload = json_decode(file_get_contents(base_path('contracts/fixtures/v1/pi_telemetry.json')), true, flags: JSON_THROW_ON_ERROR);
        $payload['event_id'] = (string) Str::uuid();
        $payload['timestamp'] = $at->format('Y-m-d\TH:i:s\Z');
        $payload['gps']['timestamp'] = $payload['timestamp'];

        foreach ($overrides as $path => $value) {
            data_set($payload, $path, $value);
        }

        $outcome = app(TelemetryInbox::class)->receive(
            "fleet.v1.{$f['org']->id}.{$f['deviceId']}.up.telemetry",
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $this->assertSame('stored', $outcome);
    }

    private function fakePrivateS3(): void
    {
        Storage::fake('s3')->buildTemporaryUrlsUsing(function ($path, $expires, $options) {
            return 'https://private-test-bucket.s3.ap-southeast-1.amazonaws.com/' . $path . '?' . http_build_query([
                'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
                'X-Amz-Signature' => str_repeat('a', 64),
                'X-Amz-Date' => now()->format('Ymd\THis\Z'),
                'X-Amz-Credential' => 'test/' . now()->format('Ymd') . '/ap-southeast-1/s3/aws4_request',
                'X-Amz-SignedHeaders' => 'host',
                'X-Amz-Expires' => PrivateAssets::LINK_TTL_SECONDS,
                'response-content-disposition' => $options['ResponseContentDisposition'],
                'response-content-type' => $options['ResponseContentType'],
                'response-cache-control' => $options['ResponseCacheControl'],
            ]);
        });
    }

    private function pngBytes(): string
    {
        $img = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        imagedestroy($img);
        return $bytes;
    }

    private function createActiveShift(array $f, CarbonImmutable $startedAt): string
    {
        $credId = (string) Str::uuid();
        DB::table('driver_credentials')->insert([
            'id' => $credId,
            'organization_id' => $f['org']->id,
            'driver_id' => $f['driverId'],
            'credential_hmac' => hash('sha256', $credId),
            'hash_key_version' => '1',
            'type' => 'nfc_uid_sha256',
            'assurance' => 'uid_low',
            'issued_at' => $startedAt->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shiftId = (string) Str::uuid();
        DB::table('driver_shifts')->insert([
            'id' => $shiftId,
            'organization_id' => $f['org']->id,
            'vehicle_id' => $f['vehicleId'],
            'driver_id' => $f['driverId'],
            'credential_id' => $credId,
            'device_installation_id' => $f['installationId'],
            'state' => 'active',
            'started_at' => $startedAt,
            'last_authenticated_at' => $startedAt,
            'attribution_expires_at' => now()->addHours(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $shiftId;
    }

    public function test_extreme_deceleration_telemetry_creates_deduplicated_critical_incident_and_alert(): void
    {
        $f = $this->setupTenant('decel');

        // Active shift for the driver so driver attribution is populated on the incident
        $shiftId = $this->createActiveShift($f, $f['start']->subMinutes(10));

        // Two extreme deceleration samples 5 seconds apart within the same 5-minute deduplication bucket
        $this->sendPiPacket($f, $f['start'], [
            'vehicle.speed_kmh' => 92,
            'driving_behaviour.acceleration.y_mps2' => -10.4,
            'driving_behaviour.acceleration.x_mps2' => 1.2,
            'gps.latitude' => 3.1415,
            'gps.longitude' => 101.6869,
        ]);
        $this->sendPiPacket($f, $f['start']->addSeconds(5), [
            'vehicle.speed_kmh' => 10,
            'driving_behaviour.acceleration.y_mps2' => -9.2,
            'gps.latitude' => 3.1416,
            'gps.longitude' => 101.6870,
        ]);

        $this->artisan('telemetry:project-pending')->assertSuccessful();

        // Exactly ONE deduplicated critical incident + ONE alert occurrence
        $this->assertDatabaseCount('incidents', 1);
        $incident = DB::table('incidents')->where('organization_id', $f['org']->id)->first();
        $this->assertSame('telemetry_auto', $incident->source);
        $this->assertSame('extreme_deceleration', $incident->category);
        $this->assertSame('critical', $incident->severity);
        $this->assertSame('open', $incident->status);
        $this->assertSame($f['vehicleId'], $incident->vehicle_id);
        $this->assertSame($f['driverId'], $incident->driver_id);
        $this->assertSame($shiftId, $incident->driver_shift_id);
        $this->assertNotNull($incident->alert_occurrence_id);
        $this->assertEqualsWithDelta(3.1415, (float) $incident->latitude, 0.0001);
        $this->assertEqualsWithDelta(-10.4, (float) $incident->accel_mps2, 0.01);

        // Initial system_alert timeline entry exists
        $this->assertDatabaseHas('incident_updates', [
            'incident_id' => $incident->id,
            'update_type' => 'system_alert',
        ]);

        // Alert occurrence + notification records routed to 'incidents' for owner and manager (not viewer/driver)
        $this->assertDatabaseHas('alert_occurrences', [
            'id' => $incident->alert_occurrence_id,
            'kind' => 'incident.extreme_deceleration',
            'severity' => 'critical',
        ]);
        $this->assertDatabaseHas('notification_records', [
            'alert_occurrence_id' => $incident->alert_occurrence_id,
            'user_id' => $f['owner']->id,
            'action_route' => 'incidents',
        ]);
        $this->assertDatabaseHas('notification_records', [
            'alert_occurrence_id' => $incident->alert_occurrence_id,
            'user_id' => $f['manager']->id,
            'action_route' => 'incidents',
        ]);
        $this->assertDatabaseMissing('notification_records', [
            'alert_occurrence_id' => $incident->alert_occurrence_id,
            'user_id' => $f['driverUser']->id,
        ]);
    }

    public function test_critical_dtc_telemetry_opens_single_deduplicated_incident_per_active_episode(): void
    {
        $f = $this->setupTenant('dtcinc');

        // T+0s, T+15s (< 30s debounce): pending only, no incident yet
        $this->sendPiPacket($f, $f['start'], [
            'vehicle.dtc_codes' => ['P0300'],
            'vehicle.mil_on' => true,
            'vehicle.has_dtc_fault' => true,
        ]);
        $this->sendPiPacket($f, $f['start']->addSeconds(15), [
            'vehicle.dtc_codes' => ['P0300'],
            'vehicle.mil_on' => true,
            'vehicle.has_dtc_fault' => true,
        ]);
        $this->artisan('telemetry:project-pending')->assertSuccessful();
        $this->assertDatabaseCount('incidents', 0);

        // T+35s and T+50s (>= 30s continuous): episode confirmed active -> EXACTLY ONE critical_dtc incident + alert
        $this->sendPiPacket($f, $f['start']->addSeconds(35), [
            'vehicle.dtc_codes' => ['P0300'],
            'vehicle.mil_on' => true,
            'vehicle.has_dtc_fault' => true,
        ]);
        $this->sendPiPacket($f, $f['start']->addSeconds(50), [
            'vehicle.dtc_codes' => ['P0300'],
            'vehicle.mil_on' => true,
            'vehicle.has_dtc_fault' => true,
        ]);
        $this->artisan('telemetry:project-pending')->assertSuccessful();

        $this->assertDatabaseCount('incidents', 1);
        $incident = DB::table('incidents')->where('organization_id', $f['org']->id)->first();
        $this->assertSame('telemetry_auto', $incident->source);
        $this->assertSame('critical_dtc', $incident->category);
        $this->assertSame('critical', $incident->severity);
        $this->assertSame(['P0300'], json_decode($incident->dtc_codes, true));
        $this->assertNotNull($incident->alert_occurrence_id);
        $this->assertDatabaseHas('alert_occurrences', [
            'id' => $incident->alert_occurrence_id,
            'kind' => 'incident.critical_dtc',
            'severity' => 'critical',
        ]);
    }

    public function test_connectivity_loss_evaluator_detects_silent_vehicle_with_weak_signal_and_deduplicates(): void
    {
        $f = $this->setupTenant('connloss');
        $lastSeen = CarbonImmutable::now('UTC')->subMinutes(25);

        $shiftId = $this->createActiveShift($f, $lastSeen->subMinutes(20));

        // Send telemetry packet 25 minutes ago with poor signal (RSSI -104 dBm, RSRP -118 dBm)
        $this->sendPiPacket($f, $lastSeen, [
            'vehicle.speed_kmh' => 45,
            'network.rssi_dbm' => -104,
            'network.rsrp_dbm' => -118,
            'gps.latitude' => 3.1502,
            'gps.longitude' => 101.7123,
        ]);
        $this->artisan('telemetry:project-pending')->assertSuccessful();

        // Run connectivity evaluator twice — must open exactly 1 incident and deduplicate on second run
        $this->artisan('incidents:evaluate-connectivity --silence-minutes=15')->assertSuccessful();
        $this->artisan('incidents:evaluate-connectivity --silence-minutes=15')->assertSuccessful();

        $this->assertDatabaseCount('incidents', 1);
        $incident = DB::table('incidents')->where('organization_id', $f['org']->id)->first();
        $this->assertSame('connectivity_loss', $incident->category);
        $this->assertSame('high', $incident->severity);
        $this->assertSame($f['driverId'], $incident->driver_id);
        $this->assertEqualsWithDelta(3.1502, (float) $incident->latitude, 0.0001);
        $this->assertDatabaseHas('alert_occurrences', [
            'id' => $incident->alert_occurrence_id,
            'kind' => 'incident.connectivity_loss',
            'severity' => 'high',
        ]);
    }

    public function test_manager_incident_lifecycle_csv_export_rbac_and_tenant_isolation(): void
    {
        $f = $this->setupTenant('lifecycle');
        $other = $this->setupTenant('otherorg');

        // Viewer cannot create incidents (403)
        $this->actingAs($f['viewer'])
            ->postJson("{$f['base']}/incidents", [
                'vehicle_id' => $f['vehicleId'],
                'category' => 'accident',
                'severity' => 'high',
                'title' => 'Unauthorized creation',
            ])
            ->assertForbidden();

        // Manager creates a manual incident (with formula-like title to test CSV injection sanitization!)
        $createRes = $this->actingAs($f['manager'])
            ->postJson("{$f['base']}/incidents", [
                'vehicle_id' => $f['vehicleId'],
                'driver_id' => $f['driverId'],
                'category' => 'accident',
                'severity' => 'critical',
                'title' => '=SUM(1+1) Highway rear-end collision',
                'description' => 'Minor bumper damage on North-South Expressway.',
                'latitude' => 3.1390,
                'longitude' => 101.6869,
                'speed_kmh' => 42.5,
                'dtc_codes' => ['p0300'],
            ])
            ->assertCreated();

        $incidentId = $createRes->json('data.item.id');
        $this->assertSame('manager_manual', $createRes->json('data.item.source'));
        $this->assertSame(['P0300'], $createRes->json('data.item.dtc_codes'));

        // List with filters + summary counts (accessible by viewer too)
        $this->actingAs($f['viewer'])
            ->getJson("{$f['base']}/incidents?severity=critical&category=accident")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.summary.open', 1)
            ->assertJsonPath('data.summary.critical_open', 1)
            ->assertJsonPath('data.items.0.registration_number', 'VEH-001')
            ->assertJsonPath('data.items.0.driver_name', 'Road Driver');

        // Dashboard reflects open_incidents and critical_open_incidents
        $this->actingAs($f['owner'])
            ->getJson("{$f['base']}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.open_incidents', 1)
            ->assertJsonPath('data.critical_open_incidents', 1);

        // Append follow-up updates (dispatch + insurance_claim)
        $this->actingAs($f['manager'])
            ->postJson("{$f['base']}/incidents/{$incidentId}/updates", [
                'update_type' => 'dispatch',
                'body' => 'Tow truck dispatched to KM 24.',
                'metadata' => ['reference' => 'TOW-8821'],
            ])
            ->assertCreated();

        $this->actingAs($f['manager'])
            ->postJson("{$f['base']}/incidents/{$incidentId}/updates", [
                'update_type' => 'insurance_claim',
                'body' => 'Submitted claim documentation to Allianz.',
                'metadata' => ['reference' => 'CLM-2026-991'],
            ])
            ->assertCreated();

        // Transition status: open -> investigating -> resolved -> closed with optimistic version check
        $this->actingAs($f['manager'])
            ->patchJson("{$f['base']}/incidents/{$incidentId}", [
                'status' => 'investigating',
                'version' => 99,
            ])
            ->assertStatus(409);

        $this->actingAs($f['manager'])
            ->patchJson("{$f['base']}/incidents/{$incidentId}", [
                'status' => 'investigating',
                'assigned_to_user_id' => $f['manager']->id,
                'version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.item.status', 'investigating')
            ->assertJsonPath('data.item.version', 2);

        $this->actingAs($f['manager'])
            ->patchJson("{$f['base']}/incidents/{$incidentId}", [
                'status' => 'resolved',
                'resolution_summary' => 'Bumper replaced and vehicle cleared for duty.',
                'version' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('data.item.status', 'resolved')
            ->assertJsonPath('data.item.version', 3);

        $detail = $this->actingAs($f['manager'])
            ->patchJson("{$f['base']}/incidents/{$incidentId}", [
                'status' => 'closed',
                'version' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.item.status', 'closed');

        $this->assertNotNull($detail->json('data.item.resolved_at'));
        $this->assertNotNull($detail->json('data.item.closed_at'));
        $this->assertGreaterThanOrEqual(5, count($detail->json('data.updates')));

        // CSV Export sanitizes spreadsheet formula injection (=SUM...)
        $csvRes = $this->actingAs($f['viewer'])
            ->getJson("{$f['base']}/incidents/export.csv")
            ->assertOk()
            ->assertJsonPath('data.filename', 'incidents.csv')
            ->assertJsonPath('data.rows', 1);
        $this->assertStringContainsString("'=SUM(1+1) Highway rear-end collision", $csvRes->json('data.csv'));

        // Cross-tenant isolation: other org owner cannot access this org's incident
        $this->actingAs($other['owner'])
            ->getJson("{$f['base']}/incidents/{$incidentId}")
            ->assertNotFound();

        $this->actingAs($other['owner'])
            ->getJson("{$other['base']}/incidents/{$incidentId}")
            ->assertNotFound();
    }

    public function test_driver_self_service_reporting_isolation_and_photo_evidence_attachments(): void
    {
        $this->fakePrivateS3();
        $f = $this->setupTenant('drvinc');

        // Second driver in same org for isolation verification
        $otherDriverUser = User::create(['email_normalized' => 'other-drv@example.test', 'display_name' => 'Other Driver']);
        Membership::create(['organization_id' => $f['org']->id, 'user_id' => $otherDriverUser->id, 'role' => 'driver', 'status' => 'active']);
        $otherDriverId = (string) Str::uuid();
        DB::table('drivers')->insert([
            'id' => $otherDriverId,
            'organization_id' => $f['org']->id,
            'user_id' => $otherDriverUser->id,
            'name' => 'Other Driver',
            'status' => 'active',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Active shift for primary driver
        $shiftId = $this->createActiveShift($f, CarbonImmutable::now('UTC')->subMinutes(15));

        // Driver cannot access org-wide manager incident endpoint
        $this->actingAs($f['driverUser'])
            ->getJson("{$f['base']}/incidents")
            ->assertForbidden();

        // Driver reports an on-road incident (auto-resolves active shift vehicle)
        $reportRes = $this->actingAs($f['driverUser'])
            ->postJson("{$f['base']}/me/incidents", [
                'category' => 'near_miss',
                'title' => 'Sudden lane cut at Federal Highway',
                'description' => 'Emergency braking to avoid motorcycle cutting across.',
                'latitude' => 3.1122,
                'longitude' => 101.6543,
            ])
            ->assertCreated();

        $incidentId = $reportRes->json('data.item.id');
        $this->assertSame('driver_report', $reportRes->json('data.item.source'));
        $this->assertSame($f['vehicleId'], $reportRes->json('data.item.vehicle_id'));
        $this->assertSame($f['driverId'], $reportRes->json('data.item.driver_id'));
        $this->assertSame($shiftId, $reportRes->json('data.item.driver_shift_id'));

        // High-priority alert notified fleet managers/owners
        $this->assertDatabaseHas('notification_records', [
            'user_id' => $f['manager']->id,
            'kind' => 'incident.driver_reported',
            'action_route' => 'incidents',
        ]);

        // Primary driver sees their own incident; second driver sees 0 and gets 404 on detail
        $this->actingAs($f['driverUser'])
            ->getJson("{$f['base']}/me/incidents")
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $this->actingAs($otherDriverUser)
            ->getJson("{$f['base']}/me/incidents")
            ->assertOk()
            ->assertJsonCount(0, 'data.items');

        $this->actingAs($otherDriverUser)
            ->getJson("{$f['base']}/me/incidents/{$incidentId}")
            ->assertNotFound();

        // Driver uploads photographic evidence (target_type=incident, purpose=incident_evidence)
        $uploadRes = $this->actingAs($f['driverUser'])
            ->postJson("{$f['base']}/files", [
                'target_type' => 'incident',
                'target_id' => $incidentId,
                'purpose' => 'incident_evidence',
                'filename' => 'dashcam-scene.png',
                'content_base64' => base64_encode($this->pngBytes()),
            ])
            ->assertCreated();

        $assetId = $uploadRes->json('data.item.id');
        $this->assertSame('incident', $uploadRes->json('data.item.target_type'));
        $this->assertSame('incident_evidence', $uploadRes->json('data.item.purpose'));

        // Other driver is blocked (403) from viewing or uploading files on primary driver's incident
        $this->actingAs($otherDriverUser)
            ->postJson("{$f['base']}/files/{$assetId}/link", ['mode' => 'view'])
            ->assertForbidden();

        // Fleet manager sees attachment on incident detail, issues signed view link, and can upload/revoke
        $this->actingAs($f['manager'])
            ->getJson("{$f['base']}/incidents/{$incidentId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.attachments')
            ->assertJsonPath('data.attachments.0.id', $assetId);

        $linkRes = $this->actingAs($f['manager'])
            ->postJson("{$f['base']}/files/{$assetId}/link", ['mode' => 'view'])
            ->assertOk();
        $this->assertStringStartsWith('https://private-test-bucket.s3.ap-southeast-1.amazonaws.com/private-assets/', $linkRes->json('data.link.url'));

        // Revoke attachment -> subsequent link returns 404
        $this->actingAs($f['manager'])
            ->postJson("{$f['base']}/files/{$assetId}/revoke", ['version' => 1])
            ->assertOk();

        $this->actingAs($f['manager'])
            ->postJson("{$f['base']}/files/{$assetId}/link", ['mode' => 'view'])
            ->assertNotFound();
    }

    public function test_incident_alerts_enqueue_and_deliver_deduplicated_emails_and_cancel_when_resolved(): void
    {
        $f = $this->setupTenant('alertmail');
        config(['mail.default' => 'array', 'app.frontend_url' => 'https://carlink.example.test']);

        // 1. Extreme deceleration telemetry triggers ONE email per incidents.write member (owner + manager = 2, not viewer/driver)
        $t0 = $f['start'];
        $this->sendPiPacket($f, $t0, [
            'gps.latitude' => 3.1390,
            'gps.longitude' => 101.6869,
            'vehicle.speed_kmh' => 45,
            'vehicle.engine_rpm' => 1900,
            'driving_behaviour.acceleration.x_mps2' => -10.4,
            'driving_behaviour.acceleration.y_mps2' => 1.5,
            'driving_behaviour.acceleration.z_mps2' => 9.8,
            'driving_behaviour.harsh_braking' => true,
        ]);
        // Duplicate impact 20s later in same 5-min window must NOT enqueue extra emails
        $this->sendPiPacket($f, $t0->addSeconds(20), [
            'gps.latitude' => 3.1390,
            'gps.longitude' => 101.6869,
            'vehicle.speed_kmh' => 12,
            'vehicle.engine_rpm' => 800,
            'driving_behaviour.acceleration.x_mps2' => -9.5,
            'driving_behaviour.acceleration.y_mps2' => 0.4,
            'driving_behaviour.acceleration.z_mps2' => 9.8,
            'driving_behaviour.harsh_braking' => true,
        ]);
        $this->artisan('telemetry:project-pending')->assertSuccessful();

        $receipts = DB::table('email_notifications')
            ->where('organization_id', $f['org']->id)
            ->where('event_key', 'incident.extreme_deceleration')
            ->get();
        $this->assertCount(2, $receipts);
        $this->assertEqualsCanonicalizing(
            [$f['owner']->id, $f['manager']->id],
            $receipts->pluck('user_id')->all(),
        );

        $outbox = DB::table('mail_outbox')
            ->where('organization_id', $f['org']->id)
            ->where('state', 'pending')
            ->get();
        $this->assertCount(2, $outbox);

        $firstPayload = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($outbox->first()->encrypted_message), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('VEH-001', $firstPayload['body']);
        $this->assertStringContainsString('CRITICAL', $firstPayload['body']);
        $this->assertStringContainsString('3.13900, 101.68690', $firstPayload['body']);
        $this->assertStringContainsString("/#/organization/{$f['org']->id}/incidents", $firstPayload['body']);

        // Dispatch pending mail -> accepted by transport
        $this->artisan('mail:dispatch-pending')->assertSuccessful();
        $this->assertSame(
            2,
            DB::table('mail_outbox')
                ->where('organization_id', $f['org']->id)
                ->where('state', 'provider_accepted')
                ->count(),
        );

        // 2. Driver-reported incident enqueues emails, and resolving before dispatch cancels stale unsent mail
        $driverReport = $this->actingAs($f['driverUser'])
            ->postJson("{$f['base']}/me/incidents", [
                'vehicle_id' => $f['vehicleId'],
                'category' => 'near_miss',
                'severity' => 'high',
                'title' => 'Close call at junction',
                'latitude' => 3.1412,
                'longitude' => 101.6901,
            ])
            ->assertCreated();
        $driverIncidentId = $driverReport->json('data.item.id');

        $pendingBeforeResolve = DB::table('mail_outbox')
            ->where('organization_id', $f['org']->id)
            ->where('state', 'pending')
            ->count();
        $this->assertSame(2, $pendingBeforeResolve);

        // Resolve immediately before mail:dispatch-pending runs -> stale mail cancelled by NotificationSourcePolicy
        $this->actingAs($f['manager'])
            ->patchJson("{$f['base']}/incidents/{$driverIncidentId}", [
                'status' => 'resolved',
                'resolution_summary' => 'Verified safe, no damage.',
                'version' => 1,
            ])
            ->assertOk();

        $this->artisan('mail:dispatch-pending')->assertSuccessful();
        $this->assertSame(
            2,
            DB::table('mail_outbox')
                ->where('organization_id', $f['org']->id)
                ->where('state', 'cancelled')
                ->count(),
        );
    }
}
