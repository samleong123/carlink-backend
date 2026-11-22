<?php

namespace App\Services\Maintenance;

use App\Services\Mileage\DistanceWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MaintenanceStatus
{
    public function cycle(string $organization, string $vehicle, object $cycle, CarbonImmutable $at): array
    {
        $interval = json_decode($cycle->interval_snapshot, true, flags: JSON_THROW_ON_ERROR);
        $timezone = DB::table('organizations')->where('id',$organization)->value('timezone') ?: 'Asia/Kuala_Lumpur';
        $status = ['remaining_km'=>null,'usage_since_service_m'=>null,'distance_status'=>'unknown_baseline','due_date'=>null,'remaining_days'=>null,'distance_quality'=>'estimated_partial_coverage'];
        if (!$cycle->serviced_at) return $status;
        $service = CarbonImmutable::parse($cycle->serviced_at)->utc();
        if ($service->gt($at)) return $status;
        if (!empty($interval['calendar_months']) || !empty($interval['calendar_days'])) {
            $date = $service->setTimezone($timezone)->startOfDay();
            $due = !empty($interval['calendar_months']) ? $date->addMonthsNoOverflow((int)$interval['calendar_months']) : $date->addDays((int)$interval['calendar_days']);
            $status['due_date'] = $due->toDateString(); $status['remaining_days'] = (int)$at->setTimezone($timezone)->startOfDay()->diffInDays($due,false);
        }
        if (empty($interval['distance_km'])) { $status['distance_status']='not_configured'; return $status; }
        $first = DB::table('mileage_speed_observations')->where('organization_id',$organization)->where('vehicle_id',$vehicle)->min('observed_at');
        $carry = $cycle->carry_in_distance_m ?? null;
        $anchor = $carry !== null && $cycle->carry_in_as_of ? CarbonImmutable::parse($cycle->carry_in_as_of)->utc() : $service;
        // A pre-monitoring service cannot silently become a zero-distance baseline.
        if ($carry === null && (!$first || CarbonImmutable::parse($first)->gt($service))) return $status;
        $window = app(DistanceWindow::class)->summarize($organization,$vehicle,$anchor,$at);
        $usage = (float)($carry ?? 0) + $window['distance_m'];
        $status['usage_since_service_m'] = round($usage,6); $status['remaining_km'] = (float)$interval['distance_km'] - $usage/1000;
        $status['distance_status'] = 'estimated_lower_bound';
        $status['unknown_seconds'] = max(0,$anchor->diffInSeconds($at) - $window['covered_seconds']);
        return $status;
    }
}
