<?php
namespace App\Services\Mail;
use App\Services\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Only reported samples and compatible covered intervals; no fabricated journeys/odometer/fuel. */
class DrivingEmailAnalytics
{
    public function samples(string $org,CarbonImmutable $from,CarbonImmutable $to,?string $driver=null)
    {
        $q=DB::table('telemetry_samples as s')->where('s.organization_id',$org)->where('s.occurred_at','>=',$from->max(app(Entitlements::class)->cutoff($org)))->where('s.occurred_at','<',$to)->where('s.occurred_at','<=',now());
        if ($driver) $q->join('driver_shifts as sh',fn($j)=>$j->on('sh.id','=','s.authorized_shift_id')->on('sh.organization_id','=','s.organization_id')->on('sh.vehicle_id','=','s.vehicle_id')->on('sh.device_installation_id','=','s.installation_id'))
            ->where('sh.driver_id',$driver)->whereColumn('s.occurred_at','>=','sh.started_at')->whereColumn('s.occurred_at','<=','sh.attribution_expires_at')->whereRaw('s.occurred_at <= COALESCE(sh.ended_at, sh.attribution_expires_at)');
        return $q->select('s.*');
    }
    public function summarize(string $org,CarbonImmutable $from,CarbonImmutable $to,?string $driver=null,bool $safety=false): array
    {
        $previous=[]; $samples=0; $vehicles=[]; $metres=0.0; $fuel=0.0; $seconds=0.0; $fuelSeconds=0.0; $moving=0.0; $alignedMetres=0.0; $alignedFuel=0.0; $max=null;
        $maxGap=app(Entitlements::class)->maxCompatibleGapSeconds($org);
        foreach ($this->samples($org,$from,$to,$driver)->orderBy('s.occurred_at')->orderBy('s.id')->cursor() as $b) {
            $samples++; $vehicles[$b->vehicle_id]=true; $a=$previous[$b->vehicle_id] ?? null;
            if ($b->speed_kmh !== null && $b->speed_kmh>=0) $max=$max===null ? (float)$b->speed_kmh : max($max,(float)$b->speed_kmh);
            if ($a && $a->installation_id===$b->installation_id && $a->source_format===$b->source_format && (!$driver || $a->authorized_shift_id===$b->authorized_shift_id)) {
                $dt=CarbonImmutable::parse($a->occurred_at)->diffInSeconds(CarbonImmutable::parse($b->occurred_at),false);
                if ($dt>0 && $dt<=$maxGap) {
                    $speed=$a->speed_kmh!==null && $b->speed_kmh!==null && $a->speed_kmh>=0 && $b->speed_kmh>=0;
                    $rate=$a->fuel_rate_lph!==null && $b->fuel_rate_lph!==null && $a->fuel_rate_lph>=0 && $b->fuel_rate_lph>=0 && $a->fuel_rate_source===$b->fuel_rate_source;
                    if ($speed) { $metres+=((float)$a->speed_kmh+(float)$b->speed_kmh)*$dt/7.2; $seconds+=$dt; if ($a->speed_kmh>0 || $b->speed_kmh>0) $moving+=$dt; }
                    if ($rate) { $fuel+=((float)$a->fuel_rate_lph+(float)$b->fuel_rate_lph)*$dt/7200; $fuelSeconds+=$dt; }
                    if ($speed && $rate) { $alignedMetres+=((float)$a->speed_kmh+(float)$b->speed_kmh)*$dt/7.2; $alignedFuel+=((float)$a->fuel_rate_lph+(float)$b->fuel_rate_lph)*$dt/7200; }
                }
            }
            $previous[$b->vehicle_id]=$b;
        }
        $out=['Reported samples'=>$samples,'Vehicles with samples'=>count($vehicles),'Covered speed seconds'=>$seconds,
            'Estimated distance (km)'=>$seconds>0 ? round($metres/1000,3) : null,'Covered moving seconds'=>$seconds>0 ? $moving : null,
            'Highest reported speed (km/h)'=>$max,'Covered fuel seconds'=>$fuelSeconds,'Estimated fuel (litres)'=>$fuelSeconds>0 ? round($fuel,3) : null,
            'Aligned efficiency (litres/100 km)'=>$alignedMetres>0 ? round($alignedFuel*100000/$alignedMetres,3) : null];
        if ($safety) {
            $events=DB::table('safety_events')->where('organization_id',$org)->where('occurred_at','>=',$from->max(app(Entitlements::class)->cutoff($org)))->where('occurred_at','<',$to)->where('occurred_at','<=',now())->when($driver,fn($q)=>$q->where('driver_id',$driver));
            $out+=['Harsh braking observations'=>(clone $events)->where('state','observed')->where('kind','harsh_braking')->count(),
                'Rapid acceleration observations'=>(clone $events)->where('state','observed')->where('kind','rapid_acceleration')->count(),
                'Approved/excluded observations'=>(clone $events)->where('state','excluded')->count()];
        }
        return $out;
    }
}
