<?php

namespace App\Console\Commands;

use App\Services\Alerts\EvaluateVehicleCompliance;
use Illuminate\Console\Command;

class EvaluateCompliance extends Command
{
    protected $signature = 'compliance:evaluate {--limit=200 : Maximum vehicle obligations evaluated per run}';
    protected $description = 'Evaluate a bounded batch of vehicle calendar compliance obligations';

    public function handle(EvaluateVehicleCompliance $evaluator): int
    {
        $limit = $this->option('limit');
        if (!is_string($limit) || !ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 1000) {
            $this->error('Limit must be an integer from 1 to 1000.');
            return self::FAILURE;
        }
        $result = $evaluator->run((int) $limit);
        $this->info('Evaluated '.$result['scanned'].' obligations; created '.$result['created'].' alert occurrences.');
        return self::SUCCESS;
    }
}
