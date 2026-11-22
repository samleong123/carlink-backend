<?php

namespace App\Console\Commands;

use App\Services\Broker\BrokerOutbox;
use App\Services\Broker\BrokerPublisher;
use Illuminate\Console\Command;

class PublishBrokerOutbox extends Command
{
    protected $signature = 'outbox:publish {--limit=200}';
    protected $description = 'Publish durable telemetry application acknowledgements';
    public function handle(BrokerOutbox $outbox, BrokerPublisher $publisher): int
    {
        try { for ($i=0; $i<max(1,min(2000,(int)$this->option('limit'))); $i++) if (!$outbox->publishOne($publisher)) break; }
        catch (\Throwable $e) { $this->error('Broker publication failed; durable responsibility retained.'); return self::FAILURE; }
        return self::SUCCESS;
    }
}
