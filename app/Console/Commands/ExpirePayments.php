<?php

namespace App\Console\Commands;

use App\Models\PaymentAttempt;
use App\Services\PaymentAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpirePayments extends Command
{
    protected $signature = 'payments:expire';
    protected $description = 'Mark unconfirmed payment attempts as failed after the confirmation window';

    public function handle(PaymentAudit $audit): int
    {
        PaymentAttempt::query()->whereIn('status', ['creating', 'pending', 'creation_unknown', 'received_unverified'])
            ->where('expires_at', '<=', now())->orderBy('id')->eachById(function (PaymentAttempt $candidate) use ($audit) {
                DB::transaction(function () use ($candidate, $audit) {
                    if (!DB::table('organizations')->where('id',$candidate->organization_id)->lockForUpdate()->first()) return;
                    $payment = PaymentAttempt::query()->lockForUpdate()->find($candidate->id);
                    if (!$payment) return;
                    if (! in_array($payment->status, ['creating', 'pending', 'creation_unknown', 'received_unverified'], true) || $payment->expires_at->isFuture()) {
                        return;
                    }
                    $payment->status = 'failed';
                    $payment->failure_reason = 'unconfirmed_timeout';
                    $payment->save();
                    $audit->record($payment, 'unconfirmed_timeout', ['confirmation_received' => false]);
                });
            });
        return self::SUCCESS;
    }
}
