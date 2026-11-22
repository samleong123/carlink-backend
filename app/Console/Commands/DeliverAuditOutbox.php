<?php

namespace App\Console\Commands;

use App\Services\RequestLogWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeliverAuditOutbox extends Command
{
    protected $signature = 'logs:deliver-audit-outbox {--limit=200}';
    protected $description = 'Deliver committed business audit events to the separate logging database';

    public function handle(RequestLogWriter $writer): int
    {
        $rows = DB::table('audit_outbox')->whereNull('delivered_at')->orderBy('created_at')->limit(max(1, min(1000, (int) $this->option('limit'))))->get();
        foreach ($rows as $row) {
            try { $writer->persist(json_decode($row->event, true, flags: JSON_THROW_ON_ERROR)); }
            catch (\Throwable $e) { $this->error('Logging sink unavailable; committed outbox retained.'); return self::FAILURE; }
            DB::table('audit_outbox')->where('id', $row->id)->whereNull('delivered_at')->update(['delivered_at' => now()]);
        }
        return self::SUCCESS;
    }
}
