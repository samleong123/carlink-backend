<?php

namespace App\Services\Mileage;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Integrates a subinterval of the SAME trapezoid, rather than prorating acceleration. */
class DistanceWindow
{
    public function summarize(string $organization, string $vehicle, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $distance = 0.0; $unknown = 0.0; $covered = 0.0;
        $segments = DB::table('vehicle_distance_segments as s')->join('mileage_speed_observations as a','a.id','=','s.start_observation_id')->join('mileage_speed_observations as b','b.id','=','s.end_observation_id')
            ->where('s.organization_id',$organization)->where('s.vehicle_id',$vehicle)->whereNull('s.superseded_at')->where('s.to_at','>',$from)->where('s.from_at','<',$to)
            ->select('s.*','a.speed_kmh as start_speed','b.speed_kmh as end_speed')->cursor();
        foreach ($segments as $s) {
            $start = CarbonImmutable::parse($s->from_at)->utc(); $end = CarbonImmutable::parse($s->to_at)->utc();
            $length = $start->diffInSeconds($end); if ($length <= 0) continue;
            $left = $start->max($from); $right = $end->min($to); $seconds = $left->diffInSeconds($right);
            if ($s->distance_m === null) { $unknown += $seconds; continue; }
            $lo = $start->diffInSeconds($left)/$length; $hi = $start->diffInSeconds($right)/$length;
            $sum = (float)$s->start_speed + (float)$s->end_speed;
            $portion = $sum == 0 ? 0 : (2*(float)$s->start_speed*($hi-$lo) + ((float)$s->end_speed-(float)$s->start_speed)*($hi*$hi-$lo*$lo))/$sum;
            $distance += (float)$s->distance_m*$portion; $covered += $seconds;
        }
        return ['distance_m'=>$distance,'covered_seconds'=>$covered,'unknown_seconds'=>$unknown];
    }
}
