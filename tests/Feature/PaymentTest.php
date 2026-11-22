<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private function gatewayResponse(array $extra = []): array
    {
        return ['status' => 200, 'title' => 'Payment Link Generated', 'data' => array_merge([
            'link' => 'https://gateway.samsam123.name.my/index.html?data=sample',
            'gateway_id' => 'test-gateway-id', 'transaction_currency' => 'MYR',
            'sale_amount' => '10', 'limit' => '1', 'type' => 'card',
        ], $extra)];
    }

    private function owner(): array
    {
        $user = User::create(['email_normalized' => 'owner@example.test', 'display_name' => 'Owner']);
        $organization = Organization::create(['name' => 'Fleet', 'slug' => 'fleet']);
        Membership::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        return [$user, $organization];
    }

    public function test_gateway_request_and_single_use_success_callback_are_logged(): void
    {
        [$user, $org] = $this->owner();
        config(['payment_gateway.token' => 'test-secret', 'payment_gateway.callback_base_url' => 'https://api.example.test']);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => Http::response($this->gatewayResponse([
            'embedded' => base64_encode($request['redirect_url']),
            'redirect_url' => $request['redirect_url'],
            'token' => 'test-secret',
        ]), 200));

        $response = $this->actingAs($user)->postJson('/api/v1/organizations/'.$org->id.'/payments', [
            'payee_name' => 'Fleet owner', 'transaction_details' => 'Subscription', 'amount' => '10.00',
        ]);
        $response->assertStatus(202)->assertJsonPath('data.status', 'pending')->assertJsonMissing(['token' => 'test-secret']);
        Http::assertSent(fn (Request $request) => $request['type'] === 'card' && $request['limit'] === 1 && $request['amount'] === '10.00');
        $outgoing = Http::recorded()->first()[0];
        $path = parse_url($outgoing['redirect_url'], PHP_URL_PATH);
        $this->assertStringNotContainsString($outgoing['redirect_url'], $response->getContent());
        $this->assertStringNotContainsString(base64_encode($outgoing['redirect_url']), $response->getContent());
        $this->assertArrayNotHasKey('gateway_response', $response->json('data'));
        $response->assertJsonPath('data.payment_url', 'https://gateway.samsam123.name.my/index.html?data=sample')->assertJsonPath('data.gateway_id', 'test-gateway-id');
        $showUrl = '/api/v1/organizations/'.$org->id.'/payments/'.$response->json('data.payment_id');
        $this->getJson($showUrl)->assertOk()->assertJsonPath('data.payment.payment_url', 'https://gateway.samsam123.name.my/index.html?data=sample');
        $storedUrl = \Illuminate\Support\Facades\DB::table('payment_attempts')->where('id', $response->json('data.payment_id'))->value('checkout_url');
        $this->assertStringNotContainsString('gateway.samsam123.name.my', $storedUrl);
        $this->postJson($path, [], ['User-Agent' => 'Payment Gateway by Sam Sam'])->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->getJson($showUrl)->assertOk()->assertJsonPath('data.payment.payment_url', null);
        $this->postJson($path, [], ['User-Agent' => 'Payment Gateway by Sam Sam'])->assertStatus(409);
        $this->assertDatabaseHas('payment_attempts', ['id' => $response->json('data.payment_id'), 'status' => 'succeeded']);
        $this->assertDatabaseHas('payment_attempt_events', ['payment_attempt_id' => $response->json('data.payment_id'), 'event' => 'payment_confirmed']);
        $this->assertDatabaseCount('payment_attempt_events', 3);
    }

    public function test_missing_expected_user_agent_does_not_consume_callback(): void
    {
        [$user, $org] = $this->owner();
        $payment = PaymentAttempt::create(['organization_id' => $org->id, 'requested_by' => $user->id, 'payee_name' => 'Owner', 'transaction_details' => 'Test', 'amount' => '10.00', 'status' => 'pending', 'callback_token_hash' => hash('sha256', 'real-secret'), 'expires_at' => now()->addMinutes(30)]);
        $url = '/api/v1/payment-gateway/callback/'.$payment->id.'/real-secret';
        $this->postJson($url, [])->assertStatus(403);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->postJson($url, [], ['User-Agent' => 'Payment Gateway by Sam Sam'])->assertOk();
    }

    public function test_callback_after_deadline_fails_even_if_scheduler_has_not_run(): void
    {
        [$user, $org] = $this->owner();
        $payment = PaymentAttempt::create(['organization_id' => $org->id, 'requested_by' => $user->id, 'payee_name' => 'Owner', 'transaction_details' => 'Test', 'amount' => '10.00', 'status' => 'pending', 'callback_token_hash' => hash('sha256', 'secret'), 'expires_at' => now()->subSecond()]);
        $this->postJson('/api/v1/payment-gateway/callback/'.$payment->id.'/secret', [], ['User-Agent' => 'Payment Gateway by Sam Sam'])->assertStatus(410);
        $this->assertDatabaseHas('payment_attempts', ['id' => $payment->id, 'status' => 'failed', 'failure_reason' => 'unconfirmed_timeout']);
        $this->assertDatabaseHas('payment_attempt_events', ['payment_attempt_id' => $payment->id, 'event' => 'unconfirmed_timeout']);
        $this->artisan('payments:expire')->assertExitCode(0);
        $this->assertDatabaseCount('payment_attempt_events', 1);
    }

    public function test_expiration_job_does_not_change_confirmed_payment(): void
    {
        [$user, $org] = $this->owner();
        $payment = PaymentAttempt::create(['organization_id' => $org->id, 'requested_by' => $user->id, 'payee_name' => 'Owner', 'transaction_details' => 'Test', 'amount' => '10.00', 'status' => 'pending', 'callback_token_hash' => hash('sha256', 'secret'), 'expires_at' => now()->addMinute()]);
        $this->postJson('/api/v1/payment-gateway/callback/'.$payment->id.'/secret', [], ['User-Agent' => 'Payment Gateway by Sam Sam'])->assertOk();
        $this->travel(2)->minutes();
        $this->artisan('payments:expire')->assertExitCode(0);
        $this->assertSame('succeeded', $payment->fresh()->status);
    }

    public function test_callback_treats_gateway_body_as_opaque_even_if_form_or_invalid_json(): void
    {
        [$user, $org] = $this->owner();
        foreach ([['application/x-www-form-urlencoded', 'status=complete'], ['application/json', '{not-json'], ['text/plain', 'opaque'], [null, '']] as $case) {
            $payment = PaymentAttempt::create(['organization_id' => $org->id, 'requested_by' => $user->id, 'payee_name' => 'Owner', 'transaction_details' => 'Test', 'amount' => '10.00', 'status' => 'pending', 'callback_token_hash' => hash('sha256', 'secret'), 'expires_at' => now()->addMinutes(30)]);
            $server = ['HTTP_USER_AGENT' => 'Payment Gateway by Sam Sam'];
            if ($case[0] !== null) $server['CONTENT_TYPE'] = $case[0];
            $this->call('POST', '/api/v1/payment-gateway/callback/'.$payment->id.'/secret', [], [], [], $server, $case[1])->assertOk()->assertJsonPath('data.status', 'succeeded');
        }
    }

    public function test_callback_arriving_before_gateway_response_is_not_overwritten(): void
    {
        [$user, $org] = $this->owner();
        config(['payment_gateway.token' => 'test-secret', 'payment_gateway.callback_base_url' => 'https://api.example.test']);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = parse_url($request['redirect_url'], PHP_URL_PATH);
            $this->postJson($path, [], ['User-Agent' => 'Payment Gateway by Sam Sam'])->assertOk();
            return Http::response($this->gatewayResponse(), 200);
        });
        $response = $this->actingAs($user)->postJson('/api/v1/organizations/'.$org->id.'/payments', [
            'payee_name' => 'Fleet owner', 'transaction_details' => 'Subscription', 'amount' => '10.00',
        ]);
        $response->assertStatus(202)->assertJsonPath('data.status', 'succeeded');
        $this->assertDatabaseHas('payment_attempts', ['id' => $response->json('data.payment_id'), 'status' => 'succeeded']);
    }

    public function test_spoofed_user_agent_without_callback_secret_cannot_change_status(): void
    {
        [$user, $org] = $this->owner();
        $payment = PaymentAttempt::create(['organization_id' => $org->id, 'requested_by' => $user->id, 'payee_name' => 'Owner', 'transaction_details' => 'Test', 'amount' => '10.00', 'status' => 'pending', 'callback_token_hash' => hash('sha256', 'real-secret'), 'expires_at' => now()->addMinutes(30)]);
        $this->postJson('/api/v1/payment-gateway/callback/'.$payment->id.'/wrong', [], ['User-Agent' => 'Payment Gateway by Sam Sam'])->assertStatus(403);
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_callback_with_real_form_shape_records_and_correlates_transaction(): void
    {
        [$user, $org] = $this->owner();
        $payment = PaymentAttempt::create(['organization_id' => $org->id, 'requested_by' => $user->id, 'payee_name' => 'Owner', 'transaction_details' => 'Test', 'amount' => '10.00', 'status' => 'pending', 'gateway_id' => 'gateway-123', 'gateway_currency' => 'MYR', 'callback_token_hash' => hash('sha256', 'secret'), 'expires_at' => now()->addMinutes(30)]);
        $url = '/api/v1/payment-gateway/callback/'.$payment->id.'/secret';
        $payload = ['transaction_id' => 'gateway-123', 'transaction_date' => '2026-11-16 10:46:10', 'transaction_amount' => 10, 'transaction_currency' => 'MYR', 'transaction_status' => 'Success', 'receipt_url' => 'https://example.test/private-receipt', 'paid_date' => '2026-11-16 10:46:14'];
        $this->call('POST', $url, ['response' => json_encode($payload)], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_USER_AGENT' => 'Payment Gateway by Sam Sam'])->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertDatabaseHas('payment_attempts', ['id' => $payment->id, 'callback_transaction_id' => 'gateway-123', 'callback_transaction_status' => 'Success']);
        $this->assertStringNotContainsString('private-receipt', json_encode(\Illuminate\Support\Facades\DB::table('payment_attempt_events')->get()->all()));
    }

    public function test_mismatched_callback_fields_rejected_without_consuming_bearer(): void
    {
        [$user, $org] = $this->owner();
        $payment = PaymentAttempt::create(['organization_id' => $org->id, 'requested_by' => $user->id, 'payee_name' => 'Owner', 'transaction_details' => 'Test', 'amount' => '10.00', 'status' => 'pending', 'gateway_id' => 'gateway-123', 'gateway_currency' => 'MYR', 'callback_token_hash' => hash('sha256', 'secret'), 'expires_at' => now()->addMinutes(30)]);
        $url = '/api/v1/payment-gateway/callback/'.$payment->id.'/secret';
        foreach ([['transaction_id' => 'wrong'], ['transaction_status' => 'Failed'], ['transaction_amount' => 10.01], ['transaction_currency' => 'USD']] as $details) {
            $this->postJson($url, ['response' => json_encode($details)], ['User-Agent' => 'Payment Gateway by Sam Sam'])->assertStatus(422);
        }
        $this->assertSame('pending', $payment->fresh()->status);
        $this->postJson($url, [], ['User-Agent' => 'Payment Gateway by Sam Sam'])->assertOk();
    }

    public function test_gateway_response_cannot_expose_callback_even_when_encoded_in_link(): void
    {
        [$user, $org] = $this->owner();
        config(['payment_gateway.token' => 'test-secret', 'payment_gateway.callback_base_url' => 'https://api.example.test']);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => Http::response($this->gatewayResponse(['link' => 'https://gateway.samsam123.name.my/index.html?data='.rawurlencode(base64_encode($request['redirect_url']))]), 200));
        $response = $this->actingAs($user)->postJson('/api/v1/organizations/'.$org->id.'/payments', ['payee_name' => 'Owner', 'transaction_details' => 'Test', 'amount' => '10.00']);
        $response->assertStatus(502)->assertJsonMissingPath('data.payment_url');
        $this->assertDatabaseHas('payment_attempts', ['id' => $response->json('data.payment_id'), 'status' => 'creation_unknown']);
    }

    public function test_gateway_response_rejects_secret_in_reference_or_encoded_path(): void
    {
        [$user, $org] = $this->owner();
        config(['payment_gateway.token' => 'test-secret', 'payment_gateway.callback_base_url' => 'https://api.example.test']);
        Http::preventStrayRequests();
        foreach (['reference', 'path'] as $place) {
            Http::fake(function (Request $request) use ($place) {
                $callback = $request['redirect_url'];
                $secret = basename(parse_url($callback, PHP_URL_PATH));
                $unsafe = $place === 'reference'
                    ? ['gateway_id' => $secret]
                    : ['link' => 'https://gateway.samsam123.name.my/'.base64_encode($callback)];
                return Http::response($this->gatewayResponse($unsafe), 200);
            });
            $this->actingAs($user)->postJson('/api/v1/organizations/'.$org->id.'/payments', [
                'payee_name' => 'Owner', 'transaction_details' => 'Test', 'amount' => '10.00',
            ])->assertStatus(502)->assertJsonMissingPath('data.payment_url');
        }
    }

    public function test_gateway_response_rejects_untrusted_url_or_inconsistent_amount(): void
    {
        [$user, $org] = $this->owner();
        config(['payment_gateway.token' => 'test-secret', 'payment_gateway.callback_base_url' => 'https://api.example.test']);
        Http::preventStrayRequests();
        foreach ([
            ['link' => 'https://attacker.example/index.html?data=123'],
            ['link' => 'http://gateway.samsam123.name.my/index.html'],
            ['link' => 'https://user@gateway.samsam123.name.my/index.html'],
            ['link' => 'https://gateway.samsam123.name.my/index.html#fragment'],
            ['sale_amount' => '11.00'],
        ] as $change) {
            Http::fake(['*' => Http::response($this->gatewayResponse($change), 200)]);
            $this->actingAs($user)->postJson('/api/v1/organizations/'.$org->id.'/payments', ['payee_name' => 'Owner', 'transaction_details' => 'Test', 'amount' => '10.00'])->assertStatus(502)->assertJsonMissingPath('data.payment_url');
        }
    }

    public function test_early_callback_confirms_and_later_id_or_currency_mismatch_is_logged(): void
    {
        [$user, $org] = $this->owner();
        config(['payment_gateway.token' => 'test-secret', 'payment_gateway.callback_base_url' => 'https://api.example.test']);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = parse_url($request['redirect_url'], PHP_URL_PATH);
            $callback = ['transaction_id' => 'different-gateway-id', 'transaction_status' => 'Success', 'transaction_amount' => 10, 'transaction_currency' => 'USD'];
            $this->postJson($path, ['response' => json_encode($callback)], ['User-Agent' => 'Payment Gateway by Sam Sam'])->assertOk();
            return Http::response($this->gatewayResponse(), 200);
        });
        $response = $this->actingAs($user)->postJson('/api/v1/organizations/'.$org->id.'/payments', ['payee_name' => 'Owner', 'transaction_details' => 'Test', 'amount' => '10.00']);
        $response->assertStatus(202)->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.payment_url', null);
        $id = $response->json('data.payment_id');
        $this->assertDatabaseHas('payment_attempt_events', ['payment_attempt_id' => $id, 'event' => 'callback_transaction_mismatch']);
        $this->assertDatabaseHas('payment_attempt_events', ['payment_attempt_id' => $id, 'event' => 'callback_currency_mismatch']);
    }

    public function test_unconfirmed_payment_expires_and_records_event(): void
    {
        [$user, $org] = $this->owner();
        $payment = PaymentAttempt::create(['organization_id' => $org->id, 'requested_by' => $user->id, 'payee_name' => 'Owner', 'transaction_details' => 'Test', 'amount' => '10.00', 'status' => 'pending', 'callback_token_hash' => hash('sha256', 'secret'), 'expires_at' => now()->subSecond()]);
        $this->artisan('payments:expire')->assertExitCode(0);
        $this->assertDatabaseHas('payment_attempts', ['id' => $payment->id, 'status' => 'failed', 'failure_reason' => 'unconfirmed_timeout']);
        $this->assertDatabaseHas('payment_attempt_events', ['payment_attempt_id' => $payment->id, 'event' => 'unconfirmed_timeout']);
        $this->artisan('payments:expire')->assertExitCode(0);
        $this->assertDatabaseCount('payment_attempt_events', 1);
    }

    public function test_gateway_connection_failure_is_uncertain_not_an_automatic_retry(): void
    {
        [$user, $org] = $this->owner();
        config(['payment_gateway.token' => 'test-secret', 'payment_gateway.callback_base_url' => 'https://api.example.test']);
        Http::preventStrayRequests();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new \Illuminate\Http\Client\ConnectionException('simulated timeout');
        });
        $response = $this->actingAs($user)->postJson('/api/v1/organizations/'.$org->id.'/payments', [
            'payee_name' => 'Fleet owner', 'transaction_details' => 'Subscription', 'amount' => '10.00',
        ]);
        $response->assertStatus(502);
        $this->assertDatabaseHas('payment_attempts', ['id' => $response->json('data.payment_id'), 'status' => 'creation_unknown']);
        $this->assertSame(1, $calls);
    }

    public function test_viewer_cannot_create_payments(): void
    {
        [$user, $org] = $this->owner();
        Membership::where('organization_id', $org->id)->update(['role' => 'viewer']);
        Http::preventStrayRequests();
        $this->actingAs($user)->postJson('/api/v1/organizations/'.$org->id.'/payments', [
            'payee_name' => 'Fleet owner', 'transaction_details' => 'Subscription', 'amount' => '10.00',
        ])->assertStatus(403);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_real_http_request_against_opt_in_local_simulation(): void
    {
        $simulation = getenv('PAYMENT_SIM_URL');
        if (! $simulation) {
            $this->markTestSkipped('Set PAYMENT_SIM_URL to run the local Postman simulation.');
        }
        [$user, $org] = $this->owner();
        config(['payment_gateway.url' => $simulation, 'payment_gateway.token' => 'simulation-token', 'payment_gateway.callback_base_url' => 'https://api.example.test']);
        $this->actingAs($user)->postJson('/api/v1/organizations/'.$org->id.'/payments', [
            'payee_name' => 'Wire Test', 'transaction_details' => 'Simulation', 'amount' => '10.00',
        ])->assertStatus(202)->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_url', 'https://gateway.samsam123.name.my/index.html?data=syntheticcheckout')
            ->assertJsonPath('data.gateway_id', 'simulation-transaction-001');
    }

    public function test_real_http_connection_failure_against_opt_in_local_port(): void
    {
        $unreachable = getenv('PAYMENT_SIM_UNREACHABLE_URL');
        if (! $unreachable) {
            $this->markTestSkipped('Set PAYMENT_SIM_UNREACHABLE_URL to run the local connection failure scenario.');
        }
        [$user, $org] = $this->owner();
        config(['payment_gateway.url' => $unreachable, 'payment_gateway.token' => 'simulation-token', 'payment_gateway.callback_base_url' => 'https://api.example.test', 'payment_gateway.timeout_seconds' => 2]);
        $response = $this->actingAs($user)->postJson('/api/v1/organizations/'.$org->id.'/payments', [
            'payee_name' => 'Wire Test', 'transaction_details' => 'Simulation', 'amount' => '10.00',
        ])->assertStatus(502);
        $this->assertDatabaseHas('payment_attempts', ['id' => $response->json('data.payment_id'), 'status' => 'creation_unknown']);
    }
}
