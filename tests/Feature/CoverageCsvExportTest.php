<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Telemetry\TelemetryInbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CoverageCsvExportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config([
            'saas.enforce_subscriptions' => true,
            'saas.managed_devices' => true,
            'broker.verified_topic_acls' => true,
        ]);

        $owner = User::create([
            'email_normalized' => 'coverage-owner@example.test',
            'display_name' => 'Coverage Owner',
        ]);
        $org = Organization::create(['name' => 'Coverage Fleet', 'slug' => 'coverage-fleet']);
        Membership::create([
            'organization_id' => $org->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $plan = (string) Str::uuid();
        $terms = [
            'id' => $plan,
            'name' => 'Cellular Pro',
            'price_minor' => 2000,
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
        $at = CarbonImmutable::now('UTC')->subMinutes(10)->startOfSecond();

        DB::table('vehicles')->insert([
            'id' => $vehicle,
            'organization_id' => $org->id,
            'registration_number' => 'WXY1234',
            'registration_normalized' => 'WXY1234',
            'registration_country' => 'MY',
            'make' => 'Perodua',
            'model' => 'Myvi',
            'fuel_type' => 'petrol',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('devices')->insert([
            'id' => $device,
            'organization_id' => $org->id,
            'serial_number' => 'IOT-COV-01',
            'broker_principal' => $device,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('device_installations')->insert([
            'id' => $installation,
            'organization_id' => $org->id,
            'device_id' => $device,
            'vehicle_id' => $vehicle,
            'effective_from' => $at->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$owner, $org, $vehicle, $device, $installation, $at];
    }

    private function receivePiPacket(
        Organization $org,
        string $device,
        CarbonImmutable $at,
        array $networkOverrides = [],
        array $vehicleOverrides = [],
    ): void {
        $packet = json_decode(
            file_get_contents(base_path('contracts/fixtures/v1/pi_telemetry.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $packet['event_id'] = (string) Str::uuid();
        $packet['timestamp'] = $at->format('Y-m-d\TH:i:s\Z');
        $packet['gps']['timestamp'] = $packet['timestamp'];
        $packet['gateway']['device_id'] = 'IOT-COV-01';
        $packet['gateway']['vehicle_id'] = 'WXY1234';
        $packet['network'] = array_merge($packet['network'], $networkOverrides);
        $packet['vehicle'] = array_merge($packet['vehicle'], $vehicleOverrides);

        $outcome = app(TelemetryInbox::class)->receive(
            "fleet.v1.{$org->id}.{$device}.up.telemetry",
            json_encode($packet, JSON_THROW_ON_ERROR),
        );
        $this->assertSame('stored', $outcome);
    }

    /** @return array<int, array<string, string>> */
    private function parseCsv(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $headers = fgetcsv($stream, escape: '');
        $records = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $records[] = array_combine($headers, $row);
        }
        fclose($stream);

        return $records;
    }

    public function test_export_csv_includes_full_cellular_rf_gps_and_vehicle_metadata_without_sim_identifiers(): void
    {
        [$owner, $org, $vehicle, $device, , $at] = $this->fixture();

        $this->receivePiPacket($org, $device, $at, [
            'modem_model' => 'SIM7600G-H',
            'imei' => '860123456789012',
            'imsi' => '502123456789012',
            'registered_plmn' => '50212',
            'operator' => 'CelcomDigi',
            'network_type' => 'LTE',
            'registration_status' => 'registered',
            'roaming' => false,
            'connected' => true,
            'cell_id' => '12345678',
            'tac' => '3456',
            'lte_band' => 3,
            'earfcn' => 1650,
            'pci' => 123,
            'rsrp_dbm' => -95,
            'rsrq_db' => -11,
            'sinr_db' => 12.5,
            'rssi_dbm' => -68,
        ]);

        $this->artisan('telemetry:project-pending')->assertSuccessful();

        $query = http_build_query([
            'from' => $at->subSecond()->toIso8601String(),
            'to' => $at->addSeconds(5)->toIso8601String(),
            'export' => 1,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/organizations/{$org->id}/coverage-map?{$query}")
            ->assertOk()
            ->assertJsonPath('data.filename', 'coverage.csv')
            ->assertJsonPath('data.content_type', 'text/csv')
            ->assertJsonPath('data.rows', 1);

        $csv = $response->json('data.csv');
        $this->assertStringNotContainsString('860123456789012', $csv);
        $this->assertStringNotContainsString('502123456789012', $csv);

        $rows = $this->parseCsv($csv);
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame([
            'sample_id',
            'vehicle_id',
            'registration_number',
            'vehicle_make',
            'vehicle_model',
            'occurred_at',
            'received_at',
            'latitude',
            'longitude',
            'position_observed_at',
            'speed_kmh',
            'engine_rpm',
            'gps_quality',
            'operator',
            'registered_plmn',
            'mcc',
            'mnc',
            'network_mode',
            'registration_status',
            'roaming',
            'connected',
            'cell_id',
            'tac',
            'lte_band',
            'earfcn',
            'pci',
            'rsrp_dbm',
            'rsrq_db',
            'sinr_db',
            'rssi_dbm',
            'modem_model',
            'source_format',
        ], array_keys($row));

        $this->assertNotEmpty($row['sample_id']);
        $this->assertSame($vehicle, $row['vehicle_id']);
        $this->assertSame('WXY1234', $row['registration_number']);
        $this->assertSame('Perodua', $row['vehicle_make']);
        $this->assertSame('Myvi', $row['vehicle_model']);
        $this->assertNotEmpty($row['occurred_at']);
        $this->assertNotEmpty($row['received_at']);
        $this->assertSame(3.139, (float) $row['latitude']);
        $this->assertSame(101.6869, (float) $row['longitude']);
        $this->assertNotEmpty($row['position_observed_at']);
        $this->assertSame(52.0, (float) $row['speed_kmh']);
        $this->assertSame(2100.0, (float) $row['engine_rpm']);
        $this->assertSame('reported_fix', $row['gps_quality']);
        $this->assertSame('CelcomDigi', $row['operator']);
        $this->assertSame('50212', $row['registered_plmn']);
        $this->assertSame('502', $row['mcc']);
        $this->assertSame('12', $row['mnc']);
        $this->assertSame('LTE', $row['network_mode']);
        $this->assertSame('registered', $row['registration_status']);
        $this->assertSame('false', $row['roaming']);
        $this->assertSame('true', $row['connected']);
        $this->assertSame('12345678', $row['cell_id']);
        $this->assertSame('3456', $row['tac']);
        $this->assertSame('3', $row['lte_band']);
        $this->assertSame('1650', $row['earfcn']);
        $this->assertSame('123', $row['pci']);
        $this->assertSame('-95', $row['rsrp_dbm']);
        $this->assertSame('-11', $row['rsrq_db']);
        $this->assertSame('12.5', $row['sinr_db']);
        $this->assertSame('-68', $row['rssi_dbm']);
        $this->assertSame('SIM7600G-H', $row['modem_model']);
        $this->assertSame('pi_snapshot_v1', $row['source_format']);
    }

    public function test_export_csv_derives_mcc_mnc_for_six_digit_plmn_and_leaves_unknowns_blank(): void
    {
        [$owner, $org, , $device, , $at] = $this->fixture();

        $this->receivePiPacket($org, $device, $at, [
            'registered_plmn' => '310260',
            'operator' => 'T-Mobile',
            'roaming' => true,
            'connected' => false,
            'sinr_db' => -5.5,
        ]);
        $this->receivePiPacket($org, $device, $at->addSeconds(5), [
            'registered_plmn' => null,
            'operator' => null,
            'network_type' => null,
            'registration_status' => null,
            'roaming' => null,
            'connected' => null,
            'cell_id' => null,
            'tac' => null,
            'lte_band' => null,
            'earfcn' => null,
            'pci' => null,
            'rsrp_dbm' => null,
            'rsrq_db' => null,
            'sinr_db' => null,
            'rssi_dbm' => null,
            'modem_model' => null,
        ]);

        $this->artisan('telemetry:project-pending')->assertSuccessful();

        $query = http_build_query([
            'from' => $at->subSecond()->toIso8601String(),
            'to' => $at->addSeconds(10)->toIso8601String(),
            'export' => 1,
        ]);

        $csv = $this->actingAs($owner)
            ->getJson("/api/v1/organizations/{$org->id}/coverage-map?{$query}")
            ->assertOk()
            ->assertJsonPath('data.rows', 2)
            ->json('data.csv');

        $rows = $this->parseCsv($csv);
        $this->assertCount(2, $rows);

        $byPlmn = collect($rows)->keyBy('registered_plmn');
        $sixDigit = $byPlmn->get('310260');
        $this->assertNotNull($sixDigit);
        $this->assertSame('310', $sixDigit['mcc']);
        $this->assertSame('260', $sixDigit['mnc']);
        $this->assertSame('true', $sixDigit['roaming']);
        $this->assertSame('false', $sixDigit['connected']);
        $this->assertSame('-5.5', $sixDigit['sinr_db']);

        $blank = $byPlmn->get('');
        $this->assertNotNull($blank);
        $this->assertSame('', $blank['mcc']);
        $this->assertSame('', $blank['mnc']);
        $this->assertSame('', $blank['roaming']);
        $this->assertSame('', $blank['connected']);
        $this->assertSame('', $blank['rsrp_dbm']);
        $this->assertSame('', $blank['sinr_db']);
        $this->assertSame('', $blank['lte_band']);
    }

    public function test_export_csv_sanitizes_formula_injection_across_string_columns_without_altering_negative_numbers(): void
    {
        [$owner, $org, $vehicle, $device, , $at] = $this->fixture();

        $this->receivePiPacket($org, $device, $at);
        $this->artisan('telemetry:project-pending')->assertSuccessful();

        DB::table('vehicles')->where('id', $vehicle)->update([
            'registration_number' => '=CMD|calc',
            'make' => '+Toyota',
            'model' => '@Corolla',
        ]);
        DB::table('telemetry_samples')->update([
            'network_data' => json_encode([
                'operator' => '=DANGEROUS()',
                'registered_plmn' => '-INVALID',
                'network_type' => "\tLTE",
                'registration_status' => "\rregistered",
                'cell_id' => '+999',
                'tac' => '@TAC1',
                'modem_model' => '=MODEM()',
                'rsrp_dbm' => -120,
                'rsrq_db' => -14,
                'rssi_dbm' => -75,
                'sinr_db' => -3.5,
            ], JSON_THROW_ON_ERROR),
        ]);

        $query = http_build_query([
            'from' => $at->subSecond()->toIso8601String(),
            'to' => $at->addSeconds(5)->toIso8601String(),
            'export' => 1,
        ]);

        $csv = $this->actingAs($owner)
            ->getJson("/api/v1/organizations/{$org->id}/coverage-map?{$query}")
            ->assertOk()
            ->json('data.csv');

        $rows = $this->parseCsv($csv);
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame("'=CMD|calc", $row['registration_number']);
        $this->assertSame("'+Toyota", $row['vehicle_make']);
        $this->assertSame("'@Corolla", $row['vehicle_model']);
        $this->assertSame("'=DANGEROUS()", $row['operator']);
        $this->assertSame("'-INVALID", $row['registered_plmn']);
        $this->assertSame('', $row['mcc']);
        $this->assertSame('', $row['mnc']);
        $this->assertSame("'\tLTE", $row['network_mode']);
        $this->assertSame("'\rregistered", $row['registration_status']);
        $this->assertSame("'+999", $row['cell_id']);
        $this->assertSame("'@TAC1", $row['tac']);
        $this->assertSame("'=MODEM()", $row['modem_model']);

        // Negative numeric RF metrics must remain unquoted numeric values
        $this->assertSame('-120', $row['rsrp_dbm']);
        $this->assertSame('-14', $row['rsrq_db']);
        $this->assertSame('-75', $row['rssi_dbm']);
        $this->assertSame('-3.5', $row['sinr_db']);
    }

    public function test_paginated_json_includes_vehicle_metadata_and_respects_tenant_and_permission_boundaries(): void
    {
        [$owner, $org, $vehicle, $device, , $at] = $this->fixture();

        $this->receivePiPacket($org, $device, $at);
        $this->artisan('telemetry:project-pending')->assertSuccessful();

        $otherOrg = Organization::create(['name' => 'Other Fleet', 'slug' => 'other-fleet']);
        $otherUser = User::create([
            'email_normalized' => 'other-cov@example.test',
            'display_name' => 'Other User',
        ]);
        Membership::create([
            'organization_id' => $otherOrg->id,
            'user_id' => $otherUser->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $query = http_build_query([
            'from' => $at->subSecond()->toIso8601String(),
            'to' => $at->addSeconds(5)->toIso8601String(),
        ]);

        $this->actingAs($owner)
            ->getJson("/api/v1/organizations/{$org->id}/coverage-map?{$query}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.vehicle_id', $vehicle)
            ->assertJsonPath('data.items.0.registration_number', 'WXY1234')
            ->assertJsonPath('data.items.0.vehicle_make', 'Perodua')
            ->assertJsonPath('data.items.0.vehicle_model', 'Myvi')
            ->assertJsonPath('data.items.0.network_data.registered_plmn', '50212');

        // Dashboard-only staff member lacks coverage.read and coverage.export
        $staff = User::create([
            'email_normalized' => 'dashboard-staff@example.test',
            'display_name' => 'Dashboard Staff',
        ]);
        Membership::create([
            'organization_id' => $org->id,
            'user_id' => $staff->id,
            'role' => 'module_dashboard',
            'status' => 'active',
        ]);

        $this->actingAs($staff)
            ->getJson("/api/v1/organizations/{$org->id}/coverage-map?{$query}")
            ->assertForbidden();
        $this->actingAs($staff)
            ->getJson("/api/v1/organizations/{$org->id}/coverage-map?{$query}&export=1")
            ->assertForbidden();

        // Cross-tenant user cannot read or export
        $this->actingAs($otherUser)
            ->getJson("/api/v1/organizations/{$org->id}/coverage-map?{$query}&export=1")
            ->assertNotFound();
    }

    public function test_export_csv_allows_multi_day_timeframes_beyond_24_hours(): void
    {
        [$owner, $org, , $device, , $at] = $this->fixture();

        $this->receivePiPacket($org, $device, $at);
        $this->artisan('telemetry:project-pending')->assertSuccessful();

        $query = http_build_query([
            'from' => $at->subDays(5)->toIso8601String(),
            'to' => $at->addDays(2)->toIso8601String(),
            'export' => 1,
        ]);

        $this->actingAs($owner)
            ->getJson("/api/v1/organizations/{$org->id}/coverage-map?{$query}")
            ->assertOk()
            ->assertJsonPath('data.rows', 1);
    }
}
