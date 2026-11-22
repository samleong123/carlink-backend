<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthProfileTest extends TestCase
{
    use RefreshDatabase;

    public static function accountsWithoutMemberships(): array
    {
        return [
            'ordinary account' => [null, 'active'],
            'platform admin' => ['admin', 'active'],
            'platform technician' => ['technician', 'active'],
            'suspended platform admin' => ['admin', 'suspended'],
        ];
    }

    #[DataProvider('accountsWithoutMemberships')]
    public function test_empty_permissions_are_an_object_without_granting_platform_authority(?string $role, string $staffStatus): void
    {
        $user = User::create(['email_normalized' => 'profile@example.test', 'display_name' => 'Profile', 'status' => 'active']);
        if ($role !== null) {
            DB::table('saas_admins')->insert([
                'user_id' => $user->id, 'role' => $role, 'status' => $staffStatus, 'version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.memberships', []);
        // Associative JSON decoding would turn both {} and [] into a PHP array.
        $data = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR)->data;
        $this->assertInstanceOf(\stdClass::class, $data->permissions);
        $this->assertSame([], get_object_vars($data->permissions));

        $activeStaff = $role !== null && $staffStatus === 'active';
        if ($activeStaff) {
            $response->assertJsonPath('data.platform.role', $role)->assertJsonPath('data.platform.version', 1);
        } else {
            $response->assertJsonPath('data.platform', null);
        }
        $this->getJson('/api/v1/platform/plans')->assertStatus($activeStaff && $role === 'admin' ? 200 : 403);
        $this->getJson('/api/v1/platform/devices')->assertStatus($activeStaff ? 200 : 403);
    }

    public function test_nonempty_permissions_keep_organization_keys_and_permission_arrays(): void
    {
        $user = User::create(['email_normalized' => 'owner@example.test', 'display_name' => 'Owner', 'status' => 'active']);
        $organization = Organization::create(['name' => 'Fleet', 'slug' => 'profile-fleet', 'status' => 'active']);
        $membership = Membership::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $expected = app(Permissions::class)->keys($membership);
        $this->assertNotEmpty($expected);

        $response = $this->actingAs($user)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.platform', null);
        $data = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR)->data;
        $this->assertInstanceOf(\stdClass::class, $data->permissions);
        $this->assertSame([$organization->id], array_keys(get_object_vars($data->permissions)));
        $this->assertSame($expected, $data->permissions->{$organization->id});
        $this->getJson('/api/v1/platform/plans')->assertForbidden();
    }
}
