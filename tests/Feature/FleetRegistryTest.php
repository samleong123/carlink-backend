<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $slug, string $role = 'owner'): array
    {
        $user = User::create(['email_normalized' => $slug.'@example.test', 'display_name' => $slug]);
        $org = Organization::create(['name' => $slug, 'slug' => $slug]);
        Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);
        return [$user, $org];
    }

    public function test_tenant_isolation_normalized_registration_and_stale_updates(): void
    {
        [$owner, $org] = $this->member('first');
        [$other, $org2] = $this->member('second');
        $base = '/api/v1/organizations/'.$org->id.'/vehicles';
        $this->actingAs($owner);
        $create = $this->postJson($base, ['registration_number' => 'ABC 123', 'registration_country' => 'my']);
        $create->assertCreated();
        $id = $create->json('data.item.id');
        $this->postJson($base, ['registration_number' => 'abc-123', 'registration_country' => 'MY'])->assertStatus(409);
        $this->patchJson($base.'/'.$id, ['version' => 1, 'make' => 'Example'])->assertOk()->assertJsonPath('data.item.version', 2);
        $this->patchJson($base.'/'.$id, ['version' => 1, 'make' => 'Stale'])->assertStatus(409);
        $this->actingAs($other)->getJson('/api/v1/organizations/'.$org2->id.'/vehicles/'.$id)->assertNotFound();
        $this->postJson('/api/v1/organizations/'.$org2->id.'/vehicles', ['registration_number' => 'ABC123', 'registration_country' => 'MY'])->assertCreated();
    }

    public function test_driver_cannot_read_fleet_inventory(): void
    {
        [$driver, $org] = $this->member('driver', 'driver');
        $this->actingAs($driver)->getJson('/api/v1/organizations/'.$org->id.'/drivers')->assertForbidden();
    }

    public function test_installations_reject_overlap_and_cross_tenant_vehicles(): void
    {
        [$owner, $org] = $this->member('fleet');
        [$other, $org2] = $this->member('other');
        $this->actingAs($owner);
        $base = '/api/v1/organizations/'.$org->id;
        $vehicle = $this->postJson($base.'/vehicles', ['registration_number' => 'ABC123', 'registration_country' => 'MY'])->json('data.item.id');
        $device = $this->postJson($base.'/devices', ['serial_number' => 'pi-1'])->json('data.item.id');
        $this->actingAs($other);
        $otherVehicle = $this->postJson('/api/v1/organizations/'.$org2->id.'/vehicles', ['registration_number' => 'DEF456', 'registration_country' => 'MY'])->json('data.item.id');
        $this->actingAs($owner);
        $path = $base.'/devices/'.$device.'/installations';
        $this->postJson($path, ['vehicle_id' => $otherVehicle, 'effective_from' => '2026-01-01T00:00:00Z'])->assertNotFound();
        $first = $this->postJson($path, ['vehicle_id' => $vehicle, 'effective_from' => '2026-01-01T00:00:00Z']);
        $first->assertCreated();
        $this->postJson($path, ['vehicle_id' => $vehicle, 'effective_from' => '2026-01-02T00:00:00Z'])->assertStatus(409);
        $this->postJson($base.'/vehicles/'.$vehicle.'/archive', ['version' => 1])->assertStatus(409);
        $this->postJson($path.'/'.$first->json('data.item.id').'/close', ['effective_to' => '2026-01-02T00:00:00Z', 'reason' => 'replacement'])->assertOk();
        $this->postJson($path, ['vehicle_id' => $vehicle, 'effective_from' => '2026-01-02T00:00:00Z'])->assertCreated();
    }
}
