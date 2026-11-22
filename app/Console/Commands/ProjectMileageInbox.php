<?php

namespace App\Console\Commands;

use App\Services\Mileage\SpeedLedger;
use Illuminate\Console\Command;

class ProjectMileageInbox extends Command
{
    protected $signature = 'mileage:project-inbox {inbox}';
    protected $description = 'Project one accepted telemetry inbox event into the pilot mileage ledger';

    public function handle(SpeedLedger $ledger): int
    {
        $this->line($ledger->project((string) $this->argument('inbox')));
        return self::SUCCESS;
    }
}
