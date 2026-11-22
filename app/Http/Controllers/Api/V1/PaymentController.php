<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PaymentAttempt;
use App\Services\PaymentAudit;
use App\Support\ApiResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PaymentController
{
    public function create(Request $request, string $organization, PaymentAudit $audit)
    {
        $membership = $request->attributes->get('membership');
        abort_unless(app(\App\Services\Permissions::class)->allows($membership,'payments.manage'),403);

        $data = $request->validate([
            'payee_name' => ['required', 'string', 'max:200'],
            'transaction_details' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'regex:/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/', 'numeric', 'gt:0'],
        ]);
        if (! config('payment_gateway.token') || ! config('payment_gateway.callback_base_url')) {
            return ApiResponse::error($request, 'Payment Configuration Missing', 'Payment gateway is not configured.', 503);
        }

        // Treat this per-attempt URL as a bearer credential. Only its hash is stored.
        $callbackToken = Str::random(64);
        $payment = DB::transaction(function () use ($request, $organization, $data, $callbackToken, $audit) {
            DB::table('organizations')->where('id',$organization)->lockForUpdate()->first() ?? abort(404);
            $signup=app(\App\Services\OrganizationSignups::class)->requireOpen($organization);
            $invoiceId=$request->attributes->get('billing_invoice_id');
            if ($invoiceId) {
                $invoice=DB::table('billing_invoices')->where('organization_id',$organization)->where('id',$invoiceId)->lockForUpdate()->first() ?? abort(404);
                abort_unless($invoice->status === 'issued' && !$invoice->payment_id && (int)round((float)$data['amount']*100) === (int)$invoice->amount_minor,409);
            }
            $payment = PaymentAttempt::create([
                'invoice_id'=>$invoiceId,
                'organization_id' => $organization,
                'requested_by' => $request->user()->id,
                'payee_name' => $data['payee_name'],
                'transaction_details' => $data['transaction_details'],
                'amount' => $data['amount'],
                'status' => 'creating',
                'callback_token_hash' => hash('sha256', $callbackToken),
                'expires_at' => $signup ? $signup->payment_due_at : now()->addMinutes(30),
            ]);
            $audit->record($payment, 'link_creation_requested');
            if ($invoiceId) DB::table('billing_invoices')->where('id',$invoiceId)->update(['payment_id'=>$payment->id,'updated_at'=>now()]);
            return $payment;
        });
        $callbackUrl = rtrim(config('payment_gateway.callback_base_url'), '/').'/api/v1/payment-gateway/callback/'.$payment->id.'/'.$callbackToken;

        try {
            // The gateway's interface is form encoded; the public application API remains JSON.
            $response = Http::asForm()->acceptJson()->timeout(config('payment_gateway.timeout_seconds'))->post(config('payment_gateway.url'), [
                'token' => config('payment_gateway.token'),
                'payee_name' => $data['payee_name'],
                'transaction_details' => $data['transaction_details'],
                'amount' => $payment->amount,
                'limit' => 1,
                'type' => 'card',
                'redirect_url' => $callbackUrl,
            ]);
        } catch (ConnectionException $e) {
            $this->recordProviderResult($payment, $audit, null, 'creation_unknown');
            return ApiResponse::error($request, 'Payment Request Uncertain', 'The gateway did not respond; the payment link may have been created. Do not retry without reconciliation.', 502, ['payment_id' => $payment->id]);
        }

        $gateway = $this->readGatewayResponse($response->json(), $callbackToken, $callbackUrl, $payment->amount);
        if (! $response->successful() || $gateway === null) {
            $this->recordProviderResult($payment, $audit, $response->status(), 'creation_unknown');
            return ApiResponse::error($request, 'Payment Request Uncertain', 'The gateway response could not be confirmed. Reconcile before retrying.', 502, ['payment_id' => $payment->id]);
        }

        $this->recordProviderResult($payment, $audit, $response->status(), 'pending', $gateway);
        $current = $payment->fresh();
        if (!$current) return ApiResponse::error($request,'Signup Expired','The unpaid organization was deleted while the provider request was in flight. Do not pay; contact an operator for reconciliation.',410);
        $paymentUrl = $current->status === 'pending' && $current->expires_at->isFuture() ? $current->checkout_url : null;
        return ApiResponse::success($request, ['payment_id' => $payment->id, 'status' => $current->status, 'expires_at' => $payment->expires_at->toIso8601String(), 'gateway_id' => $current->gateway_id, 'payment_url' => $paymentUrl], 'Payment Link Requested', $current->status === 'succeeded' ? 'Payment has been confirmed.' : 'Gateway response received.', 202);
    }

    public function show(Request $request, string $organization, string $payment)
    {
        $membership = $request->attributes->get('membership');
        abort_unless(app(\App\Services\Permissions::class)->allows($membership,'payments.manage'),403);
        $item = PaymentAttempt::query()->where('organization_id', $organization)->findOrFail($payment);
        $data = $item->only(['id', 'amount', 'status', 'failure_reason', 'expires_at', 'callback_received_at', 'created_at', 'gateway_id', 'gateway_currency']);
        $data['payment_url'] = $item->status === 'pending' && $item->expires_at->isFuture() ? $item->checkout_url : null;
        return ApiResponse::success($request, ['payment' => $data], 'Payment Retrieved', 'Payment status retrieved.');
    }

    public function callback(Request $request, string $payment, string $callbackToken, PaymentAudit $audit)
    {
        abort_unless(str_contains((string) $request->userAgent(), 'Payment Gateway by Sam Sam'), 403);
        $payload = $this->callbackPayload($request);
        $result = DB::transaction(function () use ($payment, $callbackToken, $audit, $payload) {
            $probe=PaymentAttempt::query()->find($payment);
            if (!$probe) return $this->archivedCallback($payment,$callbackToken,$payload);
            $org=DB::table('organizations')->where('id',$probe->organization_id)->lockForUpdate()->first();
            if (!$org) return $this->archivedCallback($payment,$callbackToken,$payload);
            $item = PaymentAttempt::query()->lockForUpdate()->find($payment);
            if (!$item) return $this->archivedCallback($payment,$callbackToken,$payload);
            abort_unless(hash_equals($item->callback_token_hash, hash('sha256', $callbackToken)), 403);
            if ($item->callback_received_at !== null || $item->status === 'succeeded') {
                return ['http_status' => 409, 'payment' => $item];
            }
            $signup=app(\App\Services\OrganizationSignups::class)->pending($item->organization_id);
            if ($signup && \Carbon\CarbonImmutable::parse($signup->payment_due_at)->lessThanOrEqualTo(now())) {
                if ($payload !== null && !$this->callbackMatches($item,$payload)) {
                    $audit->record($item,'callback_details_mismatch');
                    return ['http_status'=>422,'payment'=>$item];
                }
                // A late bearer callback is financial evidence only, never entitlement or tenant resurrection.
                $item->callback_received_at=now(); $item->status='failed'; $item->failure_reason='signup_deadline'; $item->save();
                $audit->record($item,'signup_late_callback',$this->callbackEvidence($payload));
                app(\App\Services\OrganizationSignups::class)->event($signup->id,$item->id,'late_callback_requires_reconciliation',$this->callbackEvidence($payload));
                return ['http_status'=>410,'payment'=>$item];
            }
            if ($item->expires_at->lessThanOrEqualTo(now()) || $item->status === 'failed') {
                if (in_array($item->status, ['creating', 'pending', 'creation_unknown', 'received_unverified'], true)) {
                    $item->status = 'failed';
                    $item->failure_reason = 'unconfirmed_timeout';
                    $item->save();
                    $audit->record($item, 'unconfirmed_timeout', ['confirmation_received' => false]);
                }
                return ['http_status' => 410, 'payment' => $item];
            }
            if (! in_array($item->status, ['creating', 'pending', 'creation_unknown'], true)) {
                return ['http_status' => 409, 'payment' => $item];
            }
            if ($payload !== null && ! $this->callbackMatches($item, $payload)) {
                $audit->record($item, 'callback_details_mismatch');
                return ['http_status' => 422, 'payment' => $item];
            }
            $item->callback_received_at = now();
            $item->callback_transaction_id = $payload['transaction_id'] ?? null;
            $item->callback_transaction_status = $payload['transaction_status'] ?? null;
            $item->callback_currency = $payload['transaction_currency'] ?? null;
            $item->status = 'succeeded';
            $item->failure_reason = null;
            $item->save();
            app(\App\Services\Entitlements::class)->settle($item);
            $details = ['authentication' => 'one_time_bearer_url', 'transaction_id' => $item->callback_transaction_id, 'transaction_status' => $item->callback_transaction_status, 'currency' => $item->callback_currency];
            if (isset($payload['transaction_amount'])) $details['amount_minor'] = $this->moneyInCents($payload['transaction_amount']);
            foreach (['transaction_date', 'paid_date'] as $field) if (isset($payload[$field])) $details[$field] = $payload[$field];
            $audit->record($item, 'payment_confirmed', $details);
            return ['http_status' => 200, 'payment' => $item];
        });
        if ($result['http_status'] === 409) {
            return ApiResponse::error($request, 'Callback Already Used', 'This callback is no longer valid.', 409);
        }
        if ($result['http_status'] === 410) {
            return ApiResponse::error($request, 'Callback Expired', 'This callback has expired. Late signup callbacks are retained for operator reconciliation and cannot activate an organization.', 410);
        }
        if ($result['http_status'] === 422) {
            return ApiResponse::error($request, 'Callback Details Mismatch', 'The supplied transaction details do not match this payment.', 422);
        }
        return ApiResponse::success($request, ['payment_id' => $result['payment']->id, 'status' => 'succeeded'], 'Payment Confirmed', 'The payment was confirmed.');
    }

    private function recordProviderResult(PaymentAttempt $payment, PaymentAudit $audit, ?int $httpStatus, string $status, ?array $gateway = null): void
    {
        DB::transaction(function () use ($payment, $audit, $httpStatus, $status, $gateway) {
            // Same lock order as cleanup and callback; a response can arrive after physical tenant deletion.
            $org=DB::table('organizations')->where('id',$payment->organization_id)->lockForUpdate()->first();
            $id=$payment->id;
            $payment = $org ? PaymentAttempt::query()->lockForUpdate()->find($id) : null;
            if (!$payment) {
                $archive=DB::table('signup_payment_archives')->where('id',$id)->lockForUpdate()->first() ?? abort(404);
                $values=['provider_http_status'=>$httpStatus,'reconciliation_required'=>true];
                if ($gateway) $values+=['gateway_id'=>$gateway['gateway_id'],'gateway_currency'=>$gateway['currency']];
                DB::table('signup_payment_archives')->where('id',$id)->update($values);
                app(\App\Services\OrganizationSignups::class)->event($archive->signup_id,$id,'provider_result_after_deletion',
                    ['http_status'=>$httpStatus,'outcome'=>$status,'gateway_id'=>$gateway['gateway_id'] ?? null,'currency'=>$gateway['currency'] ?? null]);
                return;
            }
            $payment->provider_http_status = $httpStatus;
            if ($gateway !== null) {
                $payment->gateway_id = $gateway['gateway_id'];
                $payment->gateway_currency = $gateway['currency'];
                $payment->checkout_url = $gateway['link'];
                if ($payment->callback_transaction_id !== null && !hash_equals($gateway['gateway_id'], $payment->callback_transaction_id)) {
                    $audit->record($payment, 'callback_transaction_mismatch', ['gateway_id' => $gateway['gateway_id'], 'callback_transaction_id' => $payment->callback_transaction_id]);
                }
                if ($payment->callback_currency !== null && $gateway['currency'] !== $payment->callback_currency) {
                    $audit->record($payment, 'callback_currency_mismatch', ['gateway_currency' => $gateway['currency'], 'callback_currency' => $payment->callback_currency]);
                }
            }
            if ($payment->status === 'creating') {
                $payment->status = $status;
            }
            $payment->save();
            $audit->record($payment, 'link_creation_result', ['http_status' => $httpStatus, 'outcome' => $status]);
        });
    }

    private function callbackEvidence(?array $payload): array
    {
        $details=['authentication'=>'one_time_bearer_url'];
        foreach (['transaction_id','transaction_status','transaction_currency','transaction_date','paid_date'] as $key) {
            if (isset($payload[$key])) $details[$key]=$payload[$key];
        }
        if (isset($payload['transaction_amount'])) $details['amount_minor']=$this->moneyInCents($payload['transaction_amount']);
        return $details;
    }
    private function archivedCallback(string $payment, string $token, ?array $payload): array
    {
        $row=DB::table('signup_payment_archives')->where('id',$payment)->lockForUpdate()->first() ?? abort(404);
        abort_unless(hash_equals($row->callback_token_hash,hash('sha256',$token)),403);
        if ($row->late_callback_at) return ['http_status'=>409];
        $item=new PaymentAttempt(); $item->forceFill(['amount'=>$row->amount,'gateway_id'=>$row->gateway_id,'gateway_currency'=>$row->gateway_currency]);
        if ($payload !== null && (!$this->callbackMatches($item,$payload) || (isset($payload['transaction_currency']) && $payload['transaction_currency'] !== $row->currency))) {
            app(\App\Services\OrganizationSignups::class)->event($row->signup_id,$payment,'archived_callback_details_mismatch');
            return ['http_status'=>422];
        }
        DB::table('signup_payment_archives')->where('id',$payment)->update(['late_callback_at'=>now(),'reconciliation_required'=>true]);
        app(\App\Services\OrganizationSignups::class)->event($row->signup_id,$payment,'late_callback_requires_reconciliation',$this->callbackEvidence($payload));
        return ['http_status'=>410];
    }

    /** Accept only the confirmed provider fields. Other response fields never reach the client. */
    private function readGatewayResponse(mixed $response, string $token, string $callbackUrl, string $amount): ?array
    {
        if (!is_array($response) || ($response['status'] ?? null) !== 200 || !is_array($response['data'] ?? null)) return null;
        $data = $response['data'];
        $link = $data['link'] ?? null;
        $gatewayId = $data['gateway_id'] ?? null;
        $currency = $data['transaction_currency'] ?? null;
        if (!is_string($link) || strlen($link) > 8192 || preg_match('/[\x00-\x20\x7f]/', $link) || (!is_string($gatewayId) && !is_int($gatewayId)) || !is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)) return null;
        $gatewayId = (string) $gatewayId;
        if (!preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $gatewayId) || $this->linkContainsSecret($gatewayId, $token, $callbackUrl)) return null;
        if (isset($data['sale_amount']) && $this->moneyInCents($data['sale_amount']) !== $this->moneyInCents($amount)) return null;
        if (isset($data['type']) && $data['type'] !== 'card') return null;
        if (isset($data['limit']) && (string) $data['limit'] !== '1') return null;
        $parts = parse_url($link);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== strtolower((string) config('payment_gateway.checkout_host')) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) return null;
        if ($this->linkContainsSecret($link, $token, $callbackUrl)) return null;
        return ['link' => $link, 'gateway_id' => $gatewayId, 'currency' => $currency];
    }

    private function linkContainsSecret(string $link, string $token, string $callbackUrl): bool
    {
        $values = [$link];
        $path = parse_url($link, PHP_URL_PATH);
        if (is_string($path)) foreach (explode('/', $path) as $part) if ($part !== '') $values[] = $part;
        $query = parse_url($link, PHP_URL_QUERY);
        if (is_string($query)) { parse_str($query, $params); array_walk_recursive($params, function ($value) use (&$values) { if (is_string($value)) $values[] = $value; }); }
        for ($depth = 0; $depth < 4; $depth++) {
            $next = [];
            foreach ($values as $value) {
                if (str_contains($value, $token) || str_contains($value, $callbackUrl)) return true;
                $decodedUrl = rawurldecode($value);
                if ($decodedUrl !== $value) $next[] = $decodedUrl;
                $decodedBase64 = base64_decode($value, true);
                if (is_string($decodedBase64) && $decodedBase64 !== $value) $next[] = $decodedBase64;
            }
            $values = $next;
        }
        foreach ($values as $value) if (str_contains($value, $token) || str_contains($value, $callbackUrl)) return true;
        return false;
    }

    /** The gateway documents no fixed callback encoding: unrecognised or missing payload remains opaque. */
    private function callbackPayload(Request $request): ?array
    {
        $raw = $request->input('response');
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (!is_array($raw)) return null;
        $result = [];
        foreach (['transaction_id', 'transaction_status', 'transaction_currency'] as $key) {
            if (isset($raw[$key]) && (is_string($raw[$key]) || is_int($raw[$key]))) $result[$key] = (string) $raw[$key];
        }
        if (array_key_exists('transaction_amount', $raw)) $result['transaction_amount'] = $raw['transaction_amount'];
        foreach (['transaction_date', 'paid_date'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key]) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/D', $raw[$key])) $result[$key] = $raw[$key];
        }
        return $result;
    }

    private function callbackMatches(PaymentAttempt $item, array $payload): bool
    {
        if ($item->invoice_id && isset($payload['transaction_currency']) && $payload['transaction_currency'] !== DB::table('billing_invoices')->where('id',$item->invoice_id)->value('currency')) return false;
        if (isset($payload['transaction_id']) && (!preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $payload['transaction_id']) || ($item->gateway_id !== null && !hash_equals($item->gateway_id, $payload['transaction_id'])))) return false;
        if (isset($payload['transaction_status']) && strcasecmp($payload['transaction_status'], 'Success') !== 0) return false;
        if (isset($payload['transaction_currency']) && (!preg_match('/^[A-Z]{3}$/D', $payload['transaction_currency']) || ($item->gateway_currency !== null && $payload['transaction_currency'] !== $item->gateway_currency))) return false;
        if (array_key_exists('transaction_amount', $payload) && ($this->moneyInCents($payload['transaction_amount']) === null || $this->moneyInCents($payload['transaction_amount']) !== $this->moneyInCents($item->amount))) return false;
        return true;
    }

    private function moneyInCents(mixed $amount): ?int
    {
        if (!is_string($amount) && !is_int($amount) && !is_float($amount)) return null;
        $value = (string) $amount;
        if (!preg_match('/^(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?$/D', $value, $matches)) return null;
        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

}
