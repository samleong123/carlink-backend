<?php

namespace App\Jobs;

use App\Services\Mail\DurableMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverMailIntent implements ShouldQueue
{
    use Queueable;
    public int $timeout=60;
    public int $tries=3;
    public function __construct(public readonly string $intentId) { $this->onQueue('auth-mail'); }
    public function backoff(): array { return [15,60,120]; }
    public function handle(DurableMail $mail): void { $mail->send($this->intentId); }
}
