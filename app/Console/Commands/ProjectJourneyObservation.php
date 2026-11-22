<?php

namespace App\Console\Commands;

use App\Services\Journeys\JourneyDetector;
use Illuminate\Console\Command;

class ProjectJourneyObservation extends Command
{
    protected $signature = 'journeys:project-observation {observation}';
    protected $description = 'Project one accepted speed observation into the pilot journey detector';

    public function handle(JourneyDetector $detector): int
    {
        $this->line($detector->project($this->argument('observation')));
        return self::SUCCESS;
    }
}
