<?php

namespace App\Console\Commands;

use App\Jobs\DeliverMailIntent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchMailIntents extends Command
{
    protected $signature='mail:dispatch-pending {--limit=200}';
    protected $description='Dispatch ID-only high-priority jobs from durable mail intents';
    public function handle(): int
    {
        // Wipe expired encrypted contents even when delivery is disabled; receipts remain dedup/audit metadata.
        DB::table('mail_outbox')->where('state','pending')->where('expires_at','<=',now())->where(fn($q)=>$q->whereNull('locked_until')->orWhere('locked_until','<=',now()))
            ->update(['state'=>'cancelled','encrypted_message'=>null,'lease_token'=>null,'locked_until'=>null,'updated_at'=>now()]);
        if (!\App\Services\Mail\DurableMail::deliveryEnabled()) { $this->warn('Mail dispatch disabled unless Amazon SES transport is active.'); return self::SUCCESS; }
        $ids=DB::table('mail_outbox')->where('state','pending')->where('available_at','<=',now())
            ->where(fn($q)=>$q->whereNull('last_dispatched_at')->orWhere('last_dispatched_at','<=',now()->subMinutes(2)))
             ->orderByRaw("CASE WHEN kind = 'notification' THEN 1 ELSE 0 END")->orderBy('created_at')->limit(max(1,min(2000,(int)$this->option('limit'))))->get(['id','kind']);
        foreach ($ids as $intent) {
            DeliverMailIntent::dispatch($intent->id)->onQueue($intent->kind==='notification' ? 'notifications' : 'auth-mail');
            DB::table('mail_outbox')->where('id',$intent->id)->update(['last_dispatched_at'=>now()]);
        }
        $this->info('Mail intents dispatched: '.$ids->count()); return self::SUCCESS;
    }
}
