<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EvaluateMaintenance extends Command
{
    protected $signature = 'maintenance:evaluate';
    protected $description = 'Evaluate active calendar and distance maintenance cycles';
    public function handle(\App\Services\Maintenance\EvaluateMaintenance $evaluator): int
    {
        DB::table('vehicle_maintenance_plans')->where('status','active')->select('organization_id','vehicle_id')->distinct()->orderBy('organization_id')->orderBy('vehicle_id')->chunk(100, function ($rows) use ($evaluator) {
            foreach ($rows as $row) $evaluator->vehicle($row->organization_id,$row->vehicle_id);
        });
        return self::SUCCESS;
    }
}
