<?php

namespace App\Console\Commands;

use App\Services\Telemetry\ProjectAcceptedTelemetry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProjectPendingTelemetry extends Command
{
    protected $signature = 'telemetry:project-pending {--limit=2000}';
    protected $description = 'Recover and project durably accepted telemetry in event-time order';
    public function handle(ProjectAcceptedTelemetry $projector): int
    {
        $rows = DB::table('telemetry_inbox')->where('state','pending_projection')->where(fn ($q) => $q->whereNull('projection_available_at')->orWhere('projection_available_at','<=',now()))
            ->orderBy('occurred_at')->orderBy('id')->limit(max(1,min(10000,(int)$this->option('limit'))))->get(['id']);
        $failed = false;
        foreach ($rows as $row) {
            try { $projector->project($row->id); }
            catch (\DomainException $e) {
                DB::table('telemetry_inbox')->where('id',$row->id)->update(['state'=>'projection_quarantined','projection_error'=>'SAMPLE_CONFLICT']);
            } catch (\Throwable $e) {
                $failed = true;
                DB::table('telemetry_inbox')->where('id',$row->id)->update(['projection_attempts'=>DB::raw('projection_attempts + 1'),'projection_error'=>'TRANSIENT_PROJECTION_FAILURE','projection_available_at'=>now()->addMinutes(5)]);
            }
        }
        if ($failed) $this->error('Some projections failed; retry responsibility remains in the inbox.');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
