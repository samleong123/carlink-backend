<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Alerts\CreateVehicleThresholdAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class VehicleThresholdAlertTest extends TestCase
{
    use RefreshDatabase;

    private function member(Organization $organization, string $email, string $role, string $userStatus = 'active', string $membershipStatus = 'active'): User
    {
        $user = User::create(['email_normalized' => $email, 'display_name' => $email, 'status' => $userStatus]);
        Membership::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'status' => $membershipStatus]);
        return $user;
    }

    private function vehicle(Organization $organization): string
    {
        $id = (string) Str::uuid();
        DB::table('vehicles')->insert(['id' => $id, 'organization_id' => $organization->id, 'registration_number' => 'A123',
            'registration_normalized' => 'A123', 'registration_country' => 'MY', 'status' => 'active', 'version' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    public function test_duplicate_threshold_creates_one_occurrence_and_isolated_recipient_snapshot(): void
    {
        $org = Organization::create(['name' => 'Fleet', 'slug' => 'fleet']);
        $other = Organization::create(['name' => 'Other', 'slug' => 'other']);
        $owner = $this->member($org, 'owner@example.test', 'owner');
        $manager = $this->member($org, 'manager@example.test', 'manager');
        $driver = $this->member($org, 'driver@example.test', 'driver');
        $this->member($org, 'disabled@example.test', 'admin', 'suspended');
        $this->member($org, 'removed@example.test', 'admin', 'active', 'suspended');
        $foreign = $this->member($other, 'other@example.test', 'owner');
        $vehicle = $this->vehicle($org);
        $service = app(CreateVehicleThresholdAlert::class);

        $first = $service->create($org->id, $vehicle, 'maintenance-oil', 1, 'cycle-1', 'warning', 'maintenance.threshold_crossed', 'warning', now());
        $this->assertTrue($first['created']);
        $this->assertSame(2, $first['recipient_count']);
        $this->assertDatabaseHas('notification_records', ['alert_occurrence_id' => $first['id'], 'user_id' => $owner->id]);
        $this->assertDatabaseHas('notification_records', ['alert_occurrence_id' => $first['id'], 'user_id' => $manager->id]);
        $this->assertDatabaseMissing('notification_records', ['alert_occurrence_id' => $first['id'], 'user_id' => $driver->id]);
        $this->assertDatabaseMissing('notification_records', ['alert_occurrence_id' => $first['id'], 'user_id' => $foreign->id]);

        $this->actingAs($owner)->getJson('/api/v1/organizations/'.$org->id.'/notifications')->assertOk()->assertJsonCount(1, 'data.items');
        $this->actingAs($driver)->getJson('/api/v1/organizations/'.$org->id.'/notifications')->assertOk()->assertJsonCount(0, 'data.items');
        $this->member($org, 'late@example.test', 'admin');
        $again = $service->create($org->id, $vehicle, 'maintenance-oil', 2, 'cycle-1', 'warning', 'maintenance.threshold_crossed', 'critical', now());
        $this->assertSame($first['id'], $again['id']);
        $this->assertFalse($again['created']);
        $this->assertSame(2, $again['recipient_count']);
        $this->assertDatabaseCount('alert_occurrences', 1);
        $this->assertDatabaseCount('notification_records', 2);
        $this->assertDatabaseHas('alert_occurrences', ['id' => $first['id'], 'rule_version' => 1, 'severity' => 'warning']);

        $next = $service->create($org->id, $vehicle, 'maintenance-oil', 1, 'cycle-1', 'critical', 'maintenance.threshold_crossed', 'critical', now());
        $this->assertNotSame($first['id'], $next['id']);
        $this->assertSame(3, $next['recipient_count']);
    }

    public function test_cross_tenant_vehicle_and_unapproved_alert_types_are_rejected(): void
    {
        $org = Organization::create(['name' => 'Fleet', 'slug' => 'fleet']);
        $other = Organization::create(['name' => 'Other', 'slug' => 'other']);
        $service = app(CreateVehicleThresholdAlert::class);
        $vehicle = $this->vehicle($other);
        try {
            $service->create($org->id, $vehicle, 'test', 1, 'cycle', 'warning', 'maintenance.threshold_crossed', 'warning', now());
            $this->fail('Cross-tenant vehicle should be rejected.');
        } catch (InvalidArgumentException $error) {
            $this->assertSame('Unknown or inactive vehicle.', $error->getMessage());
        }
        $this->assertDatabaseCount('alert_occurrences', 0);
        $this->assertDatabaseCount('notification_records', 0);
        $this->expectException(InvalidArgumentException::class);
        $service->create($other->id, $vehicle, 'test', 1, 'cycle', 'warning', 'arbitrary.device.content', 'warning', now());
    }

    public function test_database_rejects_linking_notification_to_another_tenants_alert(): void
    {
        $origin = Organization::create(['name' => 'Origin', 'slug' => 'origin']);
        $other = Organization::create(['name' => 'Other', 'slug' => 'other']);
        $recipient = $this->member($other, 'other@example.test', 'owner');
        $vehicle = $this->vehicle($origin);
        $alert = app(CreateVehicleThresholdAlert::class)->create($origin->id, $vehicle, 'oil', 1, 'cycle', 'warning', 'maintenance.threshold_crossed', 'warning', now());
        try {
            DB::transaction(fn () => DB::table('notification_records')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => $other->id, 'user_id' => $recipient->id,
                'kind' => 'maintenance.threshold_crossed', 'severity' => 'warning', 'title' => 'Test', 'message' => 'Test',
                'alert_occurrence_id' => $alert['id'], 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->fail('Cross-tenant occurrence reference should violate the foreign key.');
        } catch (QueryException $error) {
            $this->assertContains($error->errorInfo[0], ['23000', '23503']);
        }
        $this->assertDatabaseCount('notification_records', 0);
    }
}
