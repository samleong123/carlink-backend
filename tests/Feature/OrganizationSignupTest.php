<?php
namespace Tests\Feature;

use App\Models\{Membership,Organization,User};
use App\Services\OrganizationSignups;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Crypt,DB,Http,RateLimiter,Schema};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationSignupTest extends TestCase
{
    use RefreshDatabase;
    private function fixture(): array
    {
        // Keep test browser authentication alive while travelling through the signup deadline.
        // Absolute session expiry has its own regression suite; production settings are unchanged.
        config(['session.absolute_lifetime'=>129600,'saas.enforce_subscriptions'=>true,'mail.default'=>'array','payment_gateway.token'=>'test-only','payment_gateway.callback_base_url'=>'https://api.example.test']);
        $user=User::create(['email_normalized'=>'signup@example.test','display_name'=>'Owner']);
        $plan=$this->plan();
        $this->actingAs($user)->withHeaders(['Origin'=>'http://localhost:3000'])->withSession(['authenticated_at'=>now()->timestamp,'email_link_verified_at'=>now()->timestamp]);
        return [$user,$plan];
    }
    private function plan(): string
    {
        $plan=(string)Str::uuid();
        DB::table('subscription_plans')->insert(['id'=>$plan,'name'=>'Signup Plan','price_minor'=>12000,'currency'=>'MYR','period_days'=>30,
            'vehicle_limit'=>5,'driver_limit'=>10,'retention_days'=>90,'cellular_enabled'=>true,'safety_enabled'=>true,'telemetry_min_seconds'=>5,'created_at'=>now(),'updated_at'=>now()]);
        return $plan;
    }
    private function body(string $plan): array
    {
        return ['name'=>'New Fleet','timezone'=>'Asia/Kuala_Lumpur','default_locale'=>'en','plan_id'=>$plan,'plan_version'=>1,'accept_unpaid_deletion'=>true];
    }
    private function signup(string $plan, string $key='signup-test-key-0001'): array
    {
        return $this->postJson('/api/v1/organization-signups',$this->body($plan),['Idempotency-Key'=>$key])->assertCreated()->json('data');
    }
    private function checkout(array $data): array
    {
        Http::preventStrayRequests();
        Http::fake(fn()=>Http::response(['status'=>200,'data'=>['link'=>'https://gateway.samsam123.name.my/index.html?data=synthetic',
            'gateway_id'=>'synthetic-gateway','transaction_currency'=>'MYR','sale_amount'=>'120.00','limit'=>1,'type'=>'card']],200));
        $url='/api/v1/organizations/'.$data['signup']['organization_id'].'/invoices/'.$data['invoice']['id'].'/pay';
        $payment=$this->postJson($url)->assertStatus(202)->json('data');
        return [$payment,parse_url(Http::recorded()->first()[0]['redirect_url'],PHP_URL_PATH),$url];
    }
    public function test_public_discovery_omits_inactive_plans_and_only_exposes_catalogue_fields(): void
    {
        [, $plan]=$this->fixture();
        DB::table('subscription_plans')->insert(['id'=>(string)Str::uuid(),'name'=>'Hidden','price_minor'=>100,'currency'=>'MYR','period_days'=>30,
            'vehicle_limit'=>1,'driver_limit'=>1,'retention_days'=>30,'cellular_enabled'=>false,'safety_enabled'=>false,'telemetry_min_seconds'=>5,'status'=>'inactive']);
        $r=$this->getJson('/api/v1/public/plans')->assertOk()->assertJsonCount(1,'data.items')->assertJsonPath('data.items.0.id',$plan)->assertJsonPath('data.payment_window_hours',12);
        $this->assertArrayNotHasKey('created_at',$r->json('data.items.0'));
        $this->assertArrayNotHasKey('status',$r->json('data.items.0'));
    }
    public function test_signup_email_creates_only_identity_until_explicit_confirmation(): void
    {
        config(['mail.default'=>'array']); RateLimiter::clear('magic-global'); RateLimiter::clear('magic-email:'.hash('sha256','new@example.test'));
        $this->postJson('/api/v1/auth/signup-links',['email'=>'New@example.test','return_to'=>'/signup'])->assertStatus(202);
        $this->assertDatabaseCount('organizations',0); $this->assertDatabaseCount('memberships',0); $this->assertDatabaseCount('saas_admins',0);
        $user=User::where('email_normalized','new@example.test')->firstOrFail(); $this->assertNull($user->email_verified_at);
        $mail=DB::table('mail_outbox')->where('kind','login')->first();
        $message=json_decode(Crypt::decryptString($mail->encrypted_message),true);
        preg_match('/token=([a-f0-9]{64})/',$message['body'],$match);
        $this->postJson('/api/v1/auth/magic-links/consume',['token'=>$match[1]])->assertStatus(419);
        $this->postJson('/api/v1/auth/magic-links/consume',['token'=>$match[1]],['Origin'=>'http://localhost:3000'])->assertOk()->assertJsonPath('data.return_to','/signup');
        $this->assertNotNull($user->fresh()->email_verified_at); $this->assertDatabaseCount('organizations',0);
    }
    public function test_existing_login_does_not_register_unknown_users_or_reactivate_suspended_accounts(): void
    {
        config(['mail.default'=>'array']); RateLimiter::clear('magic-global');
        $this->postJson('/api/v1/auth/magic-links',['email'=>'unknown@example.test'])->assertStatus(202);
        $this->assertDatabaseCount('users',0);
        User::create(['email_normalized'=>'disabled@example.test','display_name'=>'Disabled','status'=>'suspended']);
        $this->postJson('/api/v1/auth/signup-links',['email'=>'disabled@example.test'])->assertStatus(202);
        $this->assertDatabaseCount('magic_link_tokens',0);
        $this->assertDatabaseHas('users',['email_normalized'=>'disabled@example.test','status'=>'suspended']);
    }
    private function intent(string $plan): array
    {
        config(['mail.default'=>'array']); RateLimiter::clear('magic-global'); RateLimiter::clear('magic-email:'.hash('sha256','email-first@example.test'));
        $body=['email'=>'email-first@example.test','organization'=>$this->body($plan)];
        $this->postJson('/api/v1/auth/signup-links',$body,['Idempotency-Key'=>'email-first-signup-0001'])->assertStatus(202);
        $mail=DB::table('mail_outbox')->where('kind','login')->first();
        $message=json_decode(Crypt::decryptString($mail->encrypted_message),true);
        preg_match('/token=([a-f0-9]{64})/',$message['body'],$match);
        return [$body,$match[1],$message];
    }
    public function test_email_first_draft_is_encrypted_and_confirmation_creates_owner_invoice_once(): void
    {
        $plan=$this->plan(); [$body,$token,$message]=$this->intent($plan);
        $this->assertStringContainsString('flow=signup',$message['body']); $this->assertStringContainsString('New Fleet',$message['html']);
        $intent=DB::table('organization_signup_intents')->first();
        $this->assertStringNotContainsString('New Fleet',$intent->encrypted_payload);
        $this->assertSame($body['organization'],json_decode(Crypt::decryptString($intent->encrypted_payload),true)['organization']);
        $this->assertDatabaseCount('organizations',0); $this->assertDatabaseCount('memberships',0); $this->assertDatabaseCount('billing_invoices',0);
        $this->postJson('/api/v1/auth/signup-links',$body,['Idempotency-Key'=>'email-first-signup-0001'])->assertStatus(202);
        $this->assertDatabaseCount('magic_link_tokens',1); $this->assertDatabaseCount('mail_outbox',1);
        $this->postJson('/api/v1/auth/magic-links/consume',['token'=>$token])->assertStatus(419);
        $r=$this->postJson('/api/v1/auth/magic-links/consume',['token'=>$token],['Origin'=>'http://localhost:3000'])->assertOk();
        $signup=DB::table('organization_signups')->first(); $user=User::where('email_normalized',$body['email'])->firstOrFail();
        $r->assertJsonPath('data.return_to','/signup?signup='.$signup->id.'&checkout=1');
        $this->assertDatabaseHas('memberships',['organization_id'=>$signup->organization_id,'user_id'=>$user->id,'role'=>'owner']);
        $this->assertDatabaseHas('organization_signup_intents',['token_id'=>$intent->token_id,'encrypted_payload'=>null]);
        $this->assertDatabaseCount('organizations',1); $this->assertDatabaseCount('billing_invoices',1); $this->assertDatabaseCount('payment_attempts',0);
        $this->assertDatabaseCount('organization_subscriptions',0); $this->assertDatabaseCount('saas_admins',0);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.permissions.'.$signup->organization_id,['payments.manage','subscription.read']);
        $notice=DB::table('email_notifications')->where('event_key','invoice.issued')->first();
        $this->assertTrue(app(\App\Services\Mail\NotificationEmails::class)->eligible($notice));
        $this->postJson('/api/v1/auth/magic-links/consume',['token'=>$token],['Origin'=>'http://localhost:3000'])->assertUnprocessable();
        $this->assertDatabaseCount('organizations',1); $this->assertDatabaseCount('billing_invoices',1);
    }
    public function test_changed_plan_rolls_back_confirmation_without_consuming_the_token(): void
    {
        $plan=$this->plan(); [, $token]=$this->intent($plan);
        DB::table('subscription_plans')->where('id',$plan)->update(['version'=>2,'price_minor'=>15000]);
        $this->postJson('/api/v1/auth/magic-links/consume',['token'=>$token],['Origin'=>'http://localhost:3000'])->assertConflict();
        $this->assertDatabaseCount('organizations',0); $this->assertDatabaseCount('billing_invoices',0);
        $this->assertDatabaseHas('magic_link_tokens',['token_hash'=>hash('sha256',$token),'consumed_at'=>null]);
        $this->assertNotNull(DB::table('organization_signup_intents')->value('encrypted_payload'));
    }
    public function test_expired_intent_cannot_create_and_scheduler_erases_its_sensitive_draft(): void
    {
        $plan=$this->plan(); [, $token]=$this->intent($plan); $this->travel(10)->minutes();
        $this->postJson('/api/v1/auth/magic-links/consume',['token'=>$token],['Origin'=>'http://localhost:3000'])->assertUnprocessable();
        $this->artisan('signups:delete-unpaid')->assertExitCode(0);
        $this->assertDatabaseHas('organization_signup_intents',['encrypted_payload'=>null]);
        $this->assertDatabaseCount('organizations',0); $this->assertDatabaseCount('billing_invoices',0);
    }
    public function test_creation_requires_recent_verification_and_explicit_deletion_acceptance(): void
    {
        [, $plan]=$this->fixture();
        $this->withSession(['email_link_verified_at'=>now()->subMinutes(10)->timestamp])->postJson('/api/v1/organization-signups',$this->body($plan),['Idempotency-Key'=>'signup-test-key-0001'])->assertForbidden();
        $this->withSession(['email_link_verified_at'=>now()->timestamp])->postJson('/api/v1/organization-signups',array_replace($this->body($plan),['accept_unpaid_deletion'=>false]),['Idempotency-Key'=>'signup-test-key-0001'])->assertUnprocessable();
        $this->postJson('/api/v1/organization-signups',$this->body($plan))->assertUnprocessable(); $this->assertDatabaseCount('organizations',0);
    }
    public function test_owner_pricing_snapshot_and_billing_only_permissions_are_server_controlled(): void
    {
        [$user,$plan]=$this->fixture(); $data=$this->signup($plan); $org=$data['signup']['organization_id'];
        $this->assertDatabaseHas('memberships',['organization_id'=>$org,'user_id'=>$user->id,'role'=>'owner']);
        $this->assertSame(12000,$data['invoice']['amount_minor']); $this->assertSame('Signup Plan',$data['invoice']['plan_snapshot']['name']);
        $this->assertEqualsWithDelta(43200,CarbonImmutable::parse($data['signup']['payment_due_at'])->timestamp-now()->timestamp,1);
        $this->assertSame(43200,CarbonImmutable::parse($data['signup']['payment_due_at'])->timestamp-CarbonImmutable::parse($data['signup']['created_at'])->timestamp);
        $this->assertDatabaseHas('organizations',['id'=>$org,'status'=>'pending_payment']); $this->assertDatabaseCount('organization_subscriptions',0);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.permissions.'.$org,['payments.manage','subscription.read']);
        $this->getJson('/api/v1/organizations/'.$org.'/subscription')->assertOk()->assertJsonPath('data.access_active',false);
        config(['saas.enforce_subscriptions'=>false]);
        $this->getJson('/api/v1/organizations/'.$org.'/vehicles')->assertStatus(402);
        $this->postJson('/api/v1/organizations/'.$org.'/invitations',[])->assertStatus(402);
        $this->getJson('/api/v1/organizations/'.$org.'/invoices')->assertOk();
    }
    public function test_replays_preserve_deadline_and_changed_payload_or_duplicate_pending_signup_conflict(): void
    {
        [, $plan]=$this->fixture(); $data=$this->signup($plan); $this->travel(5)->minutes();
        $this->postJson('/api/v1/organization-signups',$this->body($plan),['Idempotency-Key'=>'signup-test-key-0001'])->assertOk()
            ->assertJsonPath('data.signup.id',$data['signup']['id'])->assertJsonPath('data.signup.payment_due_at',$data['signup']['payment_due_at']);
        $this->postJson('/api/v1/organization-signups',array_replace($this->body($plan),['name'=>'Edited']),['Idempotency-Key'=>'signup-test-key-0001'])->assertConflict();
        $this->postJson('/api/v1/organization-signups',$this->body($plan),['Idempotency-Key'=>'signup-test-key-0002'])->assertConflict();
        $this->assertDatabaseCount('organizations',1); $this->assertDatabaseCount('billing_invoices',1);
    }
    public function test_plan_version_and_account_ownership_are_enforced(): void
    {
        [, $plan]=$this->fixture(); DB::table('subscription_plans')->where('id',$plan)->update(['version'=>2]);
        $this->postJson('/api/v1/organization-signups',$this->body($plan),['Idempotency-Key'=>'signup-test-key-0001'])->assertConflict();
        DB::table('subscription_plans')->where('id',$plan)->update(['version'=>1]); $data=$this->signup($plan);
        $other=User::create(['email_normalized'=>'other@example.test','display_name'=>'Other']);
        $this->actingAs($other)->getJson('/api/v1/organization-signups/'.$data['signup']['id'])->assertNotFound();
    }
    public function test_exact_deadline_permanent_deletion_preserves_account_and_existing_organizations(): void
    {
        [$user,$plan]=$this->fixture(); $old=Organization::create(['name'=>'Existing Fleet','slug'=>'existing']);
        Membership::create(['organization_id'=>$old->id,'user_id'=>$user->id,'role'=>'owner','status'=>'active']);
        $data=$this->signup($plan); $org=$data['signup']['organization_id'];
        $this->travelTo(CarbonImmutable::parse($data['signup']['payment_due_at'])->subSecond());
        $this->artisan('signups:delete-unpaid')->assertExitCode(0); $this->assertDatabaseHas('organizations',['id'=>$org]);
        $this->travel(1)->seconds(); $this->artisan('signups:delete-unpaid')->assertExitCode(0);
        $this->assertDatabaseMissing('organizations',['id'=>$org]); $this->assertDatabaseMissing('memberships',['organization_id'=>$org]);
        $this->assertDatabaseMissing('billing_invoices',['organization_id'=>$org]); $this->assertDatabaseHas('organizations',['id'=>$old->id]); $this->assertDatabaseHas('users',['id'=>$user->id]);
        $this->assertDatabaseHas('signup_financial_archives',['signup_id'=>$data['signup']['id']]);
        $this->getJson('/api/v1/organization-signups/'.$data['signup']['id'])->assertOk()->assertJsonPath('data.signup.status','deleted')->assertJsonPath('data.invoice',null);
        $this->postJson('/api/v1/organization-signups',$this->body($plan),['Idempotency-Key'=>'signup-test-key-0001'])->assertOk()->assertJsonPath('data.signup.status','deleted');
        $this->assertDatabaseCount('organizations',1); $this->artisan('signups:delete-unpaid')->assertExitCode(0);
    }
    public function test_payment_before_deadline_activates_and_permanently_excludes_signup_from_cleanup(): void
    {
        [, $plan]=$this->fixture(); $data=$this->signup($plan); [$p,$callback]=$this->checkout($data);
        $this->assertSame(CarbonImmutable::parse($data['signup']['payment_due_at'])->timestamp,CarbonImmutable::parse($p['expires_at'])->timestamp);
        $this->travelTo(CarbonImmutable::parse($data['signup']['payment_due_at'])->subSecond());
        $this->postJson($callback,[],['User-Agent'=>'Payment Gateway by Sam Sam'])->assertOk();
        $this->assertDatabaseHas('organizations',['id'=>$data['signup']['organization_id'],'status'=>'active']);
        $this->assertDatabaseHas('organization_signups',['id'=>$data['signup']['id'],'status'=>'paid']);
        $this->travel(60)->days(); $this->artisan('signups:delete-unpaid')->assertExitCode(0);
        $this->assertDatabaseHas('organizations',['id'=>$data['signup']['organization_id']]); $this->assertDatabaseCount('signup_financial_archives',0);
    }
    public function test_plan_edits_do_not_reprice_issued_signup_invoice_and_uncertain_checkout_cannot_be_replaced(): void
    {
        [, $plan]=$this->fixture(); $data=$this->signup($plan);
        DB::table('subscription_plans')->where('id',$plan)->update(['price_minor'=>99999,'version'=>2]);
        Http::preventStrayRequests(); Http::fake(function (Request $r) {
            $this->assertSame('120.00',$r['amount']);
            throw new \Illuminate\Http\Client\ConnectionException('Synthetic timeout');
        });
        $url='/api/v1/organizations/'.$data['signup']['organization_id'].'/invoices/'.$data['invoice']['id'].'/pay';
        $this->postJson($url)->assertStatus(502);
        $this->postJson($url)->assertConflict();
        $this->assertDatabaseCount('payment_attempts',1);
        $this->assertDatabaseHas('payment_attempts',['status'=>'creation_unknown','amount'=>'120.00']);
        $this->getJson('/api/v1/organization-signups')->assertOk()->assertJsonPath('data.invoice.amount_minor',12000)->assertJsonPath('data.invoice.plan_snapshot.version',1);
    }
    public function test_deadline_blocks_checkout_and_late_live_callback_is_archived_without_settlement(): void
    {
        [, $plan]=$this->fixture(); $data=$this->signup($plan); [$p,$callback,$pay]=$this->checkout($data);
        $this->travelTo(CarbonImmutable::parse($data['signup']['payment_due_at']));
        $this->postJson($pay)->assertStatus(410);
        $this->postJson($callback,[],['User-Agent'=>'Payment Gateway by Sam Sam'])->assertStatus(410);
        $this->assertDatabaseCount('organization_subscriptions',0);
        $this->artisan('signups:delete-unpaid')->assertExitCode(0);
        $this->assertDatabaseHas('signup_payment_archives',['id'=>$p['payment_id'],'reconciliation_required'=>true]);
        $this->postJson($callback,[],['User-Agent'=>'Payment Gateway by Sam Sam'])->assertConflict();
    }
    public function test_archived_callback_requires_secret_and_matching_details_and_never_resurrects(): void
    {
        [, $plan]=$this->fixture(); $data=$this->signup($plan); [$p,$callback]=$this->checkout($data);
        $this->travel(12)->hours(); $this->artisan('signups:delete-unpaid')->assertExitCode(0);
        $this->postJson(preg_replace('#/[^/]+$#','/wrong',$callback),[],['User-Agent'=>'Payment Gateway by Sam Sam'])->assertForbidden();
        $this->postJson($callback,['response'=>json_encode(['transaction_currency'=>'USD'])],['User-Agent'=>'Payment Gateway by Sam Sam'])->assertUnprocessable();
        $this->postJson($callback,['response'=>json_encode(['transaction_id'=>'synthetic-gateway','transaction_amount'=>'120.00','transaction_currency'=>'MYR','transaction_status'=>'Success','receipt_url'=>'private-secret'])],['User-Agent'=>'Payment Gateway by Sam Sam'])->assertStatus(410);
        $this->assertDatabaseHas('signup_payment_archives',['id'=>$p['payment_id'],'reconciliation_required'=>true]);
        $this->assertDatabaseMissing('organizations',['id'=>$data['signup']['organization_id']]); $this->assertDatabaseCount('organization_subscriptions',0);
        $this->assertStringNotContainsString('private-secret',json_encode(DB::table('signup_archive_events')->get()));
    }
    public function test_provider_response_after_deletion_is_retained_without_returning_checkout(): void
    {
        [, $plan]=$this->fixture(); $data=$this->signup($plan);
        Http::preventStrayRequests(); Http::fake(function (Request $r) {
            $this->travel(12)->hours(); $this->artisan('signups:delete-unpaid')->assertExitCode(0);
            return Http::response(['status'=>200,'data'=>['link'=>'https://gateway.samsam123.name.my/index.html?data=never-expose',
                'gateway_id'=>'late-provider','transaction_currency'=>'MYR','sale_amount'=>'120.00']],200);
        });
        $this->postJson('/api/v1/organizations/'.$data['signup']['organization_id'].'/invoices/'.$data['invoice']['id'].'/pay')->assertStatus(410)->assertJsonMissingPath('data.payment_url');
        $this->assertDatabaseHas('signup_payment_archives',['gateway_id'=>'late-provider','reconciliation_required'=>true]);
        $this->assertDatabaseHas('signup_archive_events',['signup_id'=>$data['signup']['id'],'event'=>'provider_result_after_deletion']);
    }
    public function test_archives_are_saas_admin_only_and_strip_bearer_hashes_and_urls(): void
    {
        [$user,$plan]=$this->fixture(); $data=$this->signup($plan); $this->checkout($data); $this->travel(12)->hours(); $this->artisan('signups:delete-unpaid')->assertExitCode(0);
        $url='/api/v1/platform/signup-archives/'.$data['signup']['id']; $this->getJson($url)->assertForbidden();
        DB::table('saas_admins')->insert(['user_id'=>$user->id,'role'=>'admin','status'=>'active']);
        $response=$this->getJson($url)->assertOk(); $this->assertStringNotContainsString('callback_token_hash',$response->getContent()); $this->assertStringNotContainsString('checkout_url',$response->getContent());
        $this->getJson('/api/v1/platform/signup-archives')->assertOk()->assertJsonCount(1,'data.items');
    }
    public function test_unexpected_operational_data_rolls_back_cleanup_instead_of_orphaning_or_erasing_it(): void
    {
        [, $plan]=$this->fixture(); $data=$this->signup($plan); $this->travel(12)->hours();
        Schema::create('unexpected_signup_resource',function(Blueprint $t) { $t->uuid('organization_id'); });
        try {
            DB::table('unexpected_signup_resource')->insert(['organization_id'=>$data['signup']['organization_id']]);
            $this->expectException(\DomainException::class);
            try { app(OrganizationSignups::class)->deleteUnpaid($data['signup']['id']); }
            finally { $this->assertDatabaseHas('organizations',['id'=>$data['signup']['organization_id']]); $this->assertDatabaseCount('signup_financial_archives',0); }
        } finally { Schema::drop('unexpected_signup_resource'); }
    }
}
