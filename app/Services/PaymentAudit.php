<?php

namespace App\Services;

use App\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentAudit
{
    public function record(PaymentAttempt $payment, string $event, array $details = []): void
    {
        DB::table('payment_attempt_events')->insert([
            'id' => (string) Str::uuid(),
            'payment_attempt_id' => $payment->id,
            'organization_id' => $payment->organization_id,
            'event' => $event,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
