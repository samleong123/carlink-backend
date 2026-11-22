<?php

namespace App\Services\Maintenance;

use App\Services\ActionAudit;
use App\Services\Alerts\CreateVehicleThresholdAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EvaluateMaintenance
{
    public function vehicle(string $organization, string $vehicle): void
    {
        DB::transaction(function () use ($organization,$vehicle) {
            $org = DB::table('organizations')->where('id',$organization)->where('status','active')->lockForUpdate()->first();
            if (!$org) return;
            $v = DB::table('vehicles')->where('organization_id',$organization)->where('id',$vehicle)->whereIn('status',['active','maintenance'])->lockForUpdate()->first();
            if (!$v) return;
            $cycles = DB::table('maintenance_service_cycles as c')->join('vehicle_maintenance_plans as a','a.id','=','c.assignment_id')->where('c.organization_id',$organization)->where('a.vehicle_id',$vehicle)->where('a.status','active')->whereNull('c.ended_at')->select('c.*')->get();
            foreach ($cycles as $cycle) {
                $status = app(MaintenanceStatus::class)->cycle($organization,$vehicle,$cycle,CarbonImmutable::now('UTC'));
                $thresholds = json_decode($cycle->thresholds_snapshot,true,flags:JSON_THROW_ON_ERROR);
                $breached = []; $new = [];
                foreach ($thresholds as $threshold) {
                    $tests = [];
                    foreach (['remaining_km','remaining_days'] as $metric) if (isset($threshold[$metric.'_lte'])) $tests[] = $status[$metric] !== null && $status[$metric] <= $threshold[$metric.'_lte'];
                    $matched = $tests !== [] && (($threshold['match'] ?? 'any') === 'all' ? !in_array(false,$tests,true) : in_array(true,$tests,true));
                    if ($matched) {
                        $breached[$threshold['key']] = $threshold;
                        if (!DB::table('alert_occurrences')->where('organization_id',$organization)->where('rule_key','maintenance.'.$cycle->assignment_id)->where('episode_key',$cycle->id)->where('threshold_key',$threshold['key'])->exists()) $new[] = $threshold['key'];
                    }
                }
                // Persist every crossed milestone; one highest newly crossed inbox message.
                $inApp = array_values(array_filter($new, fn ($key) => in_array('in_app',$breached[$key]['channels'] ?? [],true)));
                $highest = $inApp === [] ? null : end($inApp);
                $email = array_values(array_filter($new, fn ($key) => in_array('email',$breached[$key]['channels'] ?? [],true)));
                $highestEmail = $email === [] ? null : end($email);
                foreach ($breached as $key=>$threshold) {
                    $result = app(CreateVehicleThresholdAlert::class)->create($organization,$vehicle,'maintenance.'.$cycle->assignment_id,1,$cycle->id,$key,'maintenance.threshold_crossed',$threshold['severity'],now(),$key === $highest,$status + ['crossed_stages'=>$new],$key === $highestEmail);
                    if (DB::table('alert_occurrences')->where('id',$result['id'])->value('state') === 'superseded') {
                        DB::table('alert_occurrences')->where('id',$result['id'])->update(['state'=>'open','updated_at'=>now()]);
                        $this->evidence($organization,$result['id'],'reactivated',$status);
                    }
                    $this->evidence($organization,$result['id'],'evaluated',$status);
                }
                $existing = DB::table('alert_occurrences')->where('organization_id',$organization)->where('rule_key','maintenance.'.$cycle->assignment_id)->where('episode_key',$cycle->id)->where('state','open')->get();
                foreach ($existing as $occurrence) if (!isset($breached[$occurrence->threshold_key])) {
                    DB::table('alert_occurrences')->where('id',$occurrence->id)->update(['state'=>'superseded','updated_at'=>now()]);
                    $this->evidence($organization,$occurrence->id,'superseded_by_correction',$status);
                }
            }
            $audit = app(ActionAudit::class);
            $audit->outbox($audit->event('maintenance.evaluate','succeeded','scheduler',['organization_id'=>$organization,'vehicle_id'=>$vehicle,'cycle_count'=>$cycles->count(),'browser_absence_reason'=>'non_browser_execution']));
        });
    }

    private function evidence(string $organization, string $id, string $action, array $status): void
    {
        // Preserve transitions, but don't append identical evaluation snapshots forever.
        $json = json_encode($status,JSON_THROW_ON_ERROR);
        $old = DB::table('alert_occurrences')->where('id',$id)->value('evidence');
        if ($old && json_decode($old,true) === $status && $action === 'evaluated') return;
        DB::table('alert_occurrences')->where('id',$id)->update(['evidence'=>$json]);
        DB::table('alert_transitions')->insert(['id'=>(string)Str::uuid(),'organization_id'=>$organization,'occurrence_id'=>$id,'action'=>$action,'evidence'=>$json,'created_at'=>now()]);
    }
}
