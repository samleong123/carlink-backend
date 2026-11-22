<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AbsoluteSessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    private array $headers = ['Origin' => 'http://localhost:3000'];

    private function login(): void
    {
        $user = User::create(['email_normalized' => 'session@example.test', 'display_name' => 'Session Test']);
        $token = str_repeat('c', 64);
        DB::table('magic_link_tokens')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'email_normalized' => $user->email_normalized,
            'token_hash' => hash('sha256', $token), 'purpose' => 'login', 'expires_at' => now()->addMinutes(10),
            'return_path' => '/', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/v1/auth/magic-links/consume', ['token' => $token], $this->headers)->assertOk();
    }

    public function test_session_expires_at_absolute_limit_despite_recent_activity(): void
    {
        // Isolate absolute expiration from Laravel's separate inactivity timeout.
        config(['session.lifetime' => 1440, 'session.absolute_lifetime' => 720]);
        $this->login();
        $this->travel(719)->minutes();
        $this->getJson('/api/v1/me', $this->headers)->assertOk();
        $this->travel(1)->minutes();
        $this->getJson('/api/v1/me', $this->headers)->assertUnauthorized();
        $this->getJson('/api/v1/health/live', $this->headers)->assertOk();
    }

    public function test_authenticated_session_with_missing_expiry_marker_is_invalidated(): void
    {
        $this->login();
        $this->withSession(['authenticated_at' => null]);
        $this->getJson('/api/v1/me', $this->headers)->assertUnauthorized();
    }

    public function test_unauthenticated_stateless_requests_remain_available(): void
    {
        $this->getJson('/api/v1/health/live')->assertOk();
    }
}
