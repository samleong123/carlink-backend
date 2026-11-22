<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IdentitySecurityTest extends TestCase
{
    use RefreshDatabase;

    public static function statelessHeaders(): array
    {
        return [
            'no browser origin' => [[]],
            'unrecognized browser origin' => [['Origin' => 'https://untrusted.example.test']],
        ];
    }

    #[DataProvider('statelessHeaders')]
    public function test_stateless_login_consume_does_not_burn_tokens_or_authenticate(array $headers): void
    {
        $user = User::create(['email_normalized' => 'owner@example.test', 'display_name' => 'Owner', 'status' => 'active']);
        $id = (string) Str::uuid();
        $token = str_repeat('c', 64);
        DB::table('magic_link_tokens')->insert([
            'id' => $id, 'user_id' => $user->id, 'email_normalized' => $user->email_normalized,
            'token_hash' => hash('sha256', $token), 'purpose' => 'login', 'expires_at' => now()->addMinutes(10),
            'return_path' => '/', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/magic-links/consume', ['token' => $token], $headers);

        $this->assertDatabaseHas('magic_link_tokens', ['id' => $id, 'consumed_at' => null]);
        $response->assertStatus(419)->assertJsonPath('data.error.code', 'CSRF_FAILED');
        $this->assertGuest('web');

        $this->postJson('/api/v1/auth/magic-links/consume', ['token' => $token], ['Origin' => 'http://localhost:3000'])->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_suspended_user_with_active_membership_cannot_access_tenant_or_profile(): void
    {
        $user = User::create(['email_normalized' => 'disabled@example.test', 'display_name' => 'Disabled', 'status' => 'active']);
        $organization = Organization::create(['name' => 'Fleet', 'slug' => 'fleet', 'status' => 'active']);
        Membership::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $this->actingAs($user)->getJson('/api/v1/organizations/'.$organization->id.'/vehicles')->assertOk();

        $user->update(['status' => 'suspended']);
        $this->getJson('/api/v1/organizations/'.$organization->id.'/vehicles')->assertNotFound();
        $this->getJson('/api/v1/organizations')->assertForbidden();
        $this->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_login_token_is_single_use_and_expired_token_does_not_authenticate(): void
    {
        $user = User::create(['email_normalized' => 'owner@example.test', 'display_name' => 'Owner', 'status' => 'active']);
        $token = str_repeat('a', 64);
        DB::table('magic_link_tokens')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'email_normalized' => $user->email_normalized,
            'token_hash' => hash('sha256', $token), 'purpose' => 'login', 'expires_at' => now()->addMinutes(10),
            'return_path' => '/dashboard', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $headers = ['Origin' => 'http://localhost:3000'];
        $this->postJson('/api/v1/auth/magic-links/consume', ['token' => $token], $headers)->assertOk()->assertJsonPath('data.return_to', '/dashboard');
        $this->postJson('/api/v1/auth/magic-links/consume', ['token' => $token], $headers)->assertStatus(422);
        $this->postJson('/api/v1/auth/logout', [], $headers)->assertOk();

        $expired = str_repeat('b', 64);
        DB::table('magic_link_tokens')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'email_normalized' => $user->email_normalized,
            'token_hash' => hash('sha256', $expired), 'purpose' => 'login', 'expires_at' => now()->subSecond(),
            'return_path' => '/', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/v1/auth/magic-links/consume', ['token' => $expired], $headers)->assertStatus(422);
        $this->assertGuest('web');
    }
}
