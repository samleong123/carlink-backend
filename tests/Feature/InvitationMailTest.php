<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Mail\DurableMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InvitationMailTest extends TestCase
{
    use RefreshDatabase;
    private function owner(): array
    {
        config(['mail.default'=>'array']);
        $o=Organization::create(['name'=>'Invite','slug'=>'invite']); $u=User::create(['email_normalized'=>'inviter@example.test','display_name'=>'Owner']);
        Membership::create(['organization_id'=>$o->id,'user_id'=>$u->id,'role'=>'owner','status'=>'active']);
        return [$o,$u];
    }
    private function token(object $intent): string
    {
        $m=json_decode(Crypt::decryptString($intent->encrypted_message),true);
        preg_match('/token=([a-f0-9]{64})/',$m['body'],$match); return $match[1];
    }
    public function test_invitation_is_idempotent_mailbox_bound_and_single_use_without_owner_escalation(): void
    {
        [$o,$u]=$this->owner(); $base="/api/v1/organizations/{$o->id}/invitations"; $headers=['Idempotency-Key'=>'invite-first-01'];
        $this->actingAs($u)->postJson($base,['email'=>'New@Example.test','role'=>'viewer'],$headers)->assertCreated();
        $this->postJson($base,['email'=>'new@example.test','role'=>'viewer'],$headers)->assertCreated();
        $this->assertDatabaseCount('organization_invitations',1); $this->assertDatabaseCount('mail_outbox',1);
        $this->postJson($base,['email'=>'new@example.test','role'=>'admin'],$headers)->assertStatus(409);
        $this->postJson($base,['email'=>'owner2@example.test','role'=>'owner'],['Idempotency-Key'=>'invite-second-02'])->assertUnprocessable();
        $intent=DB::table('mail_outbox')->first(); $token=$this->token($intent);
        $this->assertStringNotContainsString($token,$intent->encrypted_message);
        $this->postJson('/api/v1/invitations/accept',['token'=>$token])->assertForbidden();
        $new=User::where('email_normalized','new@example.test')->first();
        $this->actingAs($new)->postJson('/api/v1/invitations/accept',['token'=>$token])->assertOk();
        $this->postJson('/api/v1/invitations/accept',['token'=>$token])->assertUnprocessable();
        $this->assertDatabaseHas('memberships',['organization_id'=>$o->id,'user_id'=>$new->id,'role'=>'viewer']);
    }
    public function test_revoked_and_expired_invites_cancel_unsent_mail(): void
    {
        [$o,$u]=$this->owner(); $base="/api/v1/organizations/{$o->id}/invitations";
        $id=$this->actingAs($u)->postJson($base,['email'=>'revoked@example.test','role'=>'viewer'],['Idempotency-Key'=>'revoke-mail-01'])->assertCreated()->json('data.id');
        $intent=DB::table('mail_outbox')->first(); $token=$this->token($intent);
        $this->postJson($base."/$id/revoke")->assertOk(); app(DurableMail::class)->send($intent->id);
        $this->assertDatabaseHas('mail_outbox',['id'=>$intent->id,'state'=>'cancelled','encrypted_message'=>null]);
        $this->actingAs(User::where('email_normalized','revoked@example.test')->first())->postJson('/api/v1/invitations/accept',['token'=>$token])->assertUnprocessable();
    }
    public function test_login_commits_token_and_encrypted_mail_with_id_only_queue_job(): void
    {
        [$o,$u]=$this->owner(); Queue::fake();
        $this->postJson('/api/v1/auth/magic-links',['email'=>$u->email_normalized])->assertStatus(202);
        $intent=DB::table('mail_outbox')->first(); $token=$this->token($intent);
        $this->assertDatabaseHas('magic_link_tokens',['token_hash'=>hash('sha256',$token)]);
        $this->artisan('mail:dispatch-pending')->assertSuccessful();
        Queue::assertPushed(\App\Jobs\DeliverMailIntent::class,fn($job)=>$job->intentId === $intent->id && !str_contains(serialize($job),$token));
        app(DurableMail::class)->send($intent->id); app(DurableMail::class)->send($intent->id);
        $this->assertDatabaseHas('mail_outbox',['state'=>'provider_accepted','encrypted_message'=>null]);
    }
    public function test_log_and_smtp_transports_never_persist_magic_links_or_deliver_outbox_mail(): void
    {
        [$o,$u]=$this->owner();
        foreach (['log','smtp'] as $mailer) {
            config(['mail.default'=>$mailer]);
            $this->postJson('/api/v1/auth/magic-links',['email'=>$u->email_normalized])->assertStatus(202);
            $this->assertDatabaseCount('magic_link_tokens',0); $this->assertDatabaseCount('mail_outbox',0);
            $this->actingAs($u)->postJson("/api/v1/organizations/{$o->id}/invitations",['email'=>"$mailer@example.test",'role'=>'viewer'],['Idempotency-Key'=>"deny-$mailer-01"])->assertStatus(503);
        }
        config(['mail.default'=>'array']);
        $this->postJson('/api/v1/auth/magic-links',['email'=>$u->email_normalized])->assertStatus(202);
        $intent=DB::table('mail_outbox')->first();
        config(['mail.default'=>'smtp']);
        $this->expectException(\LogicException::class);
        app(DurableMail::class)->send($intent->id);
    }
    public function test_mail_failure_retains_durable_retry_without_provider_error_text(): void
    {
        [$o,$u]=$this->owner(); $this->postJson('/api/v1/auth/magic-links',['email'=>$u->email_normalized])->assertStatus(202);
        $intent=DB::table('mail_outbox')->first(); Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('private provider details'));
        try { app(DurableMail::class)->send($intent->id); $this->fail('Expected sanitized failure.'); }
        catch (\RuntimeException $e) { $this->assertStringNotContainsString('private provider details',$e->getMessage()); }
        $this->assertDatabaseHas('mail_outbox',['id'=>$intent->id,'state'=>'pending','last_error'=>'transport_failure','locked_until'=>null]);
        $this->assertNotNull(DB::table('mail_outbox')->value('encrypted_message'));
    }
}
