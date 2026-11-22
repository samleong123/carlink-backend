<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationCentreTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $email, Organization $org, string $role = 'owner'): User
    {
        $user = User::create(['email_normalized' => $email, 'display_name' => $email]);
        Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active']);
        return $user;
    }

    private function record(Organization $org, User $user, string $kind = 'maintenance.due', ?string $createdAt = null): string
    {
        $id = (string) Str::uuid();
        DB::table('notification_records')->insert(['id' => $id, 'organization_id' => $org->id, 'user_id' => $user->id,
            'kind' => $kind, 'severity' => 'warning', 'title' => 'Service due', 'message' => 'Service is due.',
            'parameters' => json_encode(['remaining_km' => 10]), 'created_at' => $createdAt ?? now(), 'updated_at' => now()]);
        return $id;
    }

    public function test_recipient_and_tenant_isolation_with_separate_read_and_archive_state(): void
    {
        $org = Organization::create(['name' => 'Fleet', 'slug' => 'fleet']);
        $otherOrg = Organization::create(['name' => 'Other', 'slug' => 'other']);
        $user = $this->member('me@example.test', $org);
        $other = $this->member('other@example.test', $org, 'driver');
        Membership::create(['organization_id' => $otherOrg->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $mine = $this->record($org, $user); $theirs = $this->record($org, $other); $elsewhere = $this->record($otherOrg, $user);
        $url = '/api/v1/organizations/'.$org->id.'/notifications';
        $list = $this->actingAs($user)->getJson($url)->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.unread_count', 1);
        $this->assertSame($mine, $list->json('data.items.0.id'));
        $this->patchJson($url.'/'.$theirs, ['read' => true])->assertNotFound();
        $this->patchJson($url.'/'.$elsewhere, ['read' => true])->assertNotFound();
        $this->postJson($url.'/mark-read', ['ids' => [$mine, $theirs, $elsewhere]])->assertOk()->assertJsonPath('data.updated_count', 1);
        $this->assertNull(DB::table('notification_records')->where('id', $theirs)->value('read_at'));
        $this->assertNull(DB::table('notification_records')->where('id', $elsewhere)->value('read_at'));
        $this->patchJson($url.'/'.$mine, ['read' => false, 'archived' => true])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.items')->assertJsonPath('data.unread_count', 0);
        $this->patchJson($url.'/'.$mine, ['archived' => false])->assertOk();
        $this->getJson($url.'/unread-count')->assertOk()->assertJsonPath('data.unread_count', 1);
    }

    public function test_mark_read_respects_snapshot_and_validates_input(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-16T10:00:00Z'));
        $org = Organization::create(['name' => 'Fleet', 'slug' => 'fleet']);
        $user = $this->member('me@example.test', $org);
        $old = $this->record($org, $user, 'old', '2026-11-16 09:00:00');
        $new = $this->record($org, $user, 'new', '2026-11-16 09:59:59');
        $url = '/api/v1/organizations/'.$org->id.'/notifications';
        $this->actingAs($user)->postJson($url.'/mark-read', ['through_created_at' => '2026-11-16T09:30:00Z'])->assertOk()->assertJsonPath('data.updated_count', 1);
        $this->assertNotNull(DB::table('notification_records')->where('id', $old)->value('read_at'));
        $this->assertNull(DB::table('notification_records')->where('id', $new)->value('read_at'));
        $this->postJson($url.'/mark-read', ['through_created_at' => '2026-11-16T11:00:00Z'])->assertStatus(422);
        $this->postJson($url.'/mark-read', ['ids' => [$new], 'through_created_at' => '2026-11-16T09:30:00Z'])->assertStatus(422);
    }

    public function test_cursor_pagination_and_filters_only_return_visible_recipient_records(): void
    {
        $org = Organization::create(['name' => 'Fleet', 'slug' => 'fleet']);
        $user = $this->member('me@example.test', $org);
        $first = $this->record($org, $user, 'maintenance.due', '2026-11-16 08:00:00');
        $second = $this->record($org, $user, 'maintenance.due', '2026-11-16 09:00:00');
        $this->record($org, $user, 'device.offline', '2026-11-16 10:00:00');
        $url = '/api/v1/organizations/'.$org->id.'/notifications';
        $p1 = $this->actingAs($user)->getJson($url.'?per_page=1&type=maintenance.due')->assertOk()->assertJsonPath('data.items.0.id', $second);
        $cursor = $p1->json('data.pagination.next_cursor');
        $this->assertNotNull($cursor);
        $this->getJson($url.'?per_page=1&type=maintenance.due&cursor='.urlencode($cursor))->assertOk()->assertJsonPath('data.items.0.id', $first);
    }

    public function test_preferences_are_scoped_and_quiet_hours_are_paired(): void
    {
        $org = Organization::create(['name' => 'Fleet', 'slug' => 'fleet']);
        $user = $this->member('me@example.test', $org); $other = $this->member('other@example.test', $org, 'driver');
        $url = '/api/v1/organizations/'.$org->id.'/notification-preferences';
        $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('data.preferences.optional_email_enabled', true);
        $this->patchJson($url, ['quiet_start' => '22:00'])->assertStatus(422);
        $this->patchJson($url, ['optional_email_enabled' => false, 'locale' => 'ms', 'quiet_start' => '22:00', 'quiet_end' => '07:00'])->assertOk()->assertJsonPath('data.preferences.optional_email_enabled', false);
        $this->actingAs($other)->getJson($url)->assertOk()->assertJsonPath('data.preferences.optional_email_enabled', true);
        $this->actingAs($user)->patchJson($url, ['quiet_start' => null, 'quiet_end' => null])->assertOk()->assertJsonPath('data.preferences.quiet_start', null);
    }
}
