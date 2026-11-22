<?php
namespace App\Console\Commands;
use App\Services\Mail\ScheduledEmails;
use Illuminate\Console\Command;

class GenerateNotificationEmails extends Command
{
    protected $signature='mail:generate-notifications {--force : Force generation regardless of local digest hour}';
    protected $description='Queue local-day fleet/driver analytics, weekly observations, score changes and subscription expiry notices';
    public function handle(ScheduledEmails $emails): int {
        $force = (bool)$this->option('force');
        $emails->run($force);
        $this->info('Notification generation completed; provider delivery is not confirmed.');
        return self::SUCCESS;
    }
}
