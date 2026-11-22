<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Permissions;
use App\Support\ApiResponse;
use App\Support\EmissionFactors;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationsAnalyticsController
{
    private function authorize(Request $request, string $key): void
    {
        abort_unless(app(Permissions::class)->allows($request->attributes->get('membership'),$key),403);
    }
    private function vehicle(string $org, string $vehicle): object
    {
        return DB::table('vehicles')->where('organization_id',$org)->where('id',$vehicle)->first() ?? abort(404);
    }
    private function window(Request $request): array
    {
        $v=$request->validate(['from'=>'required|date','to'=>'required|date|after:from','per_page'=>'nullable|integer|min:1|max:200','cursor'=>'nullable|string|max:512']);
        $from=CarbonImmutable::parse($v['from'])->utc(); $to=CarbonImmutable::parse($v['to'])->utc();
        if ($from->diffInSeconds($to) > 86400) throw ValidationException::withMessages(['to'=>['Queries are limited to 24 hours.']]);
        return [$from,$to];
    }
    private function sample(string $org, string $vehicle): ?object
    {
        return DB::table('vehicle_latest_states as l')->join('telemetry_samples as s','s.id','=','l.sample_id')
            ->where('l.organization_id',$org)->where('l.vehicle_id',$vehicle)->select('s.*')->first();
    }
    private function json(?string $raw): mixed { return $raw === null ? null : app(\App\Services\DtcPrivacy::class)->scrub(json_decode($raw,true,32,JSON_THROW_ON_ERROR)); }

    public function latest(Request $request, string $organization, string $vehicle)
    {
        $this->authorize($request,'telemetry.read'); $this->vehicle($organization,$vehicle);
        $canSeeNetwork=app(Permissions::class)->allows($request->attributes->get('membership'),'coverage.read')
            && (!config('saas.enforce_subscriptions') || in_array(app(\App\Services\Entitlements::class)->plan($organization)['cellular_enabled'] ?? false,[true,1],true));
        $s=$this->sample($organization,$vehicle);
        if ($s) {
            foreach (['quality','vehicle_data','network_data','health_data','behaviour_data'] as $key) $s->$key=$this->json($s->$key);
            if (!$canSeeNetwork) $s->network_data=null;
        }
        return ApiResponse::success($request,['sample'=>$s,'freshness'=>$this->freshness($organization,$s),'meta'=>['edge_flags_are_unverified'=>true,'session_claims_do_not_authorize_drivers'=>true,'network_gated'=>!$canSeeNetwork]],'Latest Vehicle State Retrieved');
    }
    private function freshness(string $org, ?object $s): string
    {
        if (!$s) return 'never_observed';
        $settings=$this->json(DB::table('organization_settings')->where('organization_id',$org)->value('settings')) ?? [];
        return CarbonImmutable::parse($s->occurred_at)->lt(CarbonImmutable::now('UTC')->subSeconds($settings['device_offline_after_s'] ?? 180)) ? 'stale' : 'recent_packet';
    }
    public function diagnostics(Request $request, string $organization, string $vehicle)
    {
        $this->authorize($request,'telemetry.read'); $this->vehicle($organization,$vehicle); $s=$this->sample($organization,$vehicle);
        $v=$this->json($s?->vehicle_data) ?? [];
        return ApiResponse::success($request,['occurred_at'=>$s?->occurred_at,'mil_on'=>$v['mil_on'] ?? null,'has_fault_code'=>$v['has_dtc_fault'] ?? null,'codes_stored'=>false,
            'source'=>'latest_packet_claim','scan_completeness'=>'unknown','fault_clearance_confirmed'=>false], 'Diagnostic Snapshot Retrieved');
    }
    public function energy(Request $request, string $organization, string $vehicle)
    {
        $this->authorize($request,'analytics.read'); $vehicleRow=$this->vehicle($organization,$vehicle); [$from,$to]=$this->window($request);
        $segments=DB::table('vehicle_energy_segments as e')->join('telemetry_samples as a','a.id','=','e.start_sample_id')->join('telemetry_samples as b','b.id','=','e.end_sample_id')
            ->where('e.organization_id',$organization)->where('e.vehicle_id',$vehicle)->whereNull('e.superseded_at')->where('e.to_at','>',$from)->where('e.from_at','<',$to);
        $litres=0.0; $covered=0.0; $sources=[];
        foreach ($segments->select('e.*','a.fuel_rate_lph as rate_a','b.fuel_rate_lph as rate_b')->cursor() as $s) {
            if ($s->litres === null) continue;
            $a=CarbonImmutable::parse($s->from_at); $b=CarbonImmutable::parse($s->to_at); $duration=$a->diffInSeconds($b,false);
            $left=$a->max($from); $right=$b->min($to); $seconds=$left->diffInSeconds($right,false);
            if ($duration <= 0 || $seconds <= 0) continue;
            $slope=((float)$s->rate_b-(float)$s->rate_a)/$duration;
            $rateLeft=(float)$s->rate_a+$slope*$a->diffInSeconds($left,false); $rateRight=(float)$s->rate_a+$slope*$a->diffInSeconds($right,false);
            $litres+=($rateLeft+$rateRight)*$seconds/7200; $covered+=$seconds; $sources[$s->source]=true;
        }
        $factor = EmissionFactors::resolve($organization, $vehicleRow->fuel_type ?? null);
        $fuelLitres = $covered > 0 ? round($litres, 6) : null;
        $estimatedCo2Kg = ($fuelLitres !== null && $factor['kg_per_litre'] !== null)
            ? round($litres * $factor['kg_per_litre'], 6)
            : null;
        return ApiResponse::success($request,['from'=>$from->toIso8601String(),'to'=>$to->toIso8601String(),'fuel_litres'=>$fuelLitres,
            'covered_seconds'=>$covered,'unknown_seconds'=>max(0,$from->diffInSeconds($to)-$covered),'sources'=>array_keys($sources),'quality'=>'packet_snapshot_estimate',
             'estimated_co2_kg'=>$estimatedCo2Kg,'emission_factor'=>$factor['label'],'edge_totals_used'=>false,'maf_assumptions'=>['fuel'=>$vehicleRow->fuel_type ?? 'petrol','default_air_fuel_ratio'=>14.7,'default_density_g_per_litre'=>745,'applied_values'=>'per_sample_quality.maf_assumptions; organization settings at projection time']], 'Energy Estimate Retrieved');
    }
    public function coverage(Request $request, string $organization, string $vehicle)
    {
        $this->authorize($request,'coverage.read'); $this->vehicle($organization,$vehicle); [$from,$to]=$this->window($request);
        app(\App\Services\Entitlements::class)->feature($organization,'cellular');
        $page=DB::table('telemetry_samples')->where('organization_id',$organization)->where('vehicle_id',$vehicle)->where('occurred_at','>=',$from)->where('occurred_at','<',$to)
            ->orderBy('occurred_at')->orderBy('id')->cursorPaginate((int)$request->input('per_page',50),['id','occurred_at','latitude','longitude','position_observed_at','network_data','source_format']);
        foreach ($page->items() as $s) $s->network_data=$this->json($s->network_data);
        return ApiResponse::success($request,['items'=>$page->items(),'pagination'=>['next_cursor'=>$page->nextCursor()?->encode(),'per_page'=>$page->perPage()],
            'meta'=>['measurement_kind'=>'packet_snapshot','missing_telemetry_is_not_a_dead_zone'=>true,'network_sample_timestamp'=>'unreported','geolocation_time_alignment'=>'unverified']], 'Coverage Observations Retrieved');
    }
    public function fleetStates(Request $request, string $organization)
    {
        $this->authorize($request,'dashboard.read'); $this->authorize($request,'telemetry.read');
        $v=$request->validate(['per_page'=>'nullable|integer|min:1|max:200','cursor'=>'nullable|string|max:512']);
        $page=DB::table('vehicles as v')->leftJoin('vehicle_latest_states as l',fn($j)=>$j->on('l.vehicle_id','=','v.id')->on('l.organization_id','=','v.organization_id'))
            ->leftJoin('telemetry_samples as s','s.id','=','l.sample_id')->where('v.organization_id',$organization)->where('v.status','<>','archived')->orderBy('v.id')
             ->cursorPaginate((int)($v['per_page'] ?? 50),['v.id','v.registration_number','v.make','v.model','v.status','l.version','s.occurred_at','s.received_at','s.speed_kmh','s.latitude','s.longitude','s.source_format','s.vehicle_data']);
        $membership=$request->attributes->get('membership');
        $permissions=app(Permissions::class);
        $canSeeShifts=$permissions->allows($membership,'shifts.read') || $permissions->allows($membership,'drivers.read');
        $vehicleIds=collect($page->items())->pluck('id')->all();
        $shiftsByVehicle=($canSeeShifts && $vehicleIds !== [])
            ? DB::table('driver_shifts as sh')
                ->join('drivers as d',fn($j)=>$j->on('d.id','=','sh.driver_id')->on('d.organization_id','=','sh.organization_id'))
                ->where('sh.organization_id',$organization)
                ->whereIn('sh.vehicle_id',$vehicleIds)
                ->where('sh.state','active')
                ->whereNull('sh.ended_at')
                ->where('sh.attribution_expires_at','>',now())
                ->select(['sh.id','sh.vehicle_id','sh.driver_id','d.name as driver_name','sh.started_at','sh.attribution_expires_at'])
                ->get()
                ->keyBy('vehicle_id')
            : collect();
        foreach ($page->items() as $s) {
            $s->freshness=$s->occurred_at ? $this->freshness($organization,$s) : 'never_observed';
            $claim=$this->json($s->vehicle_data); $s->has_fault_code=$claim['has_dtc_fault'] ?? null; $s->mil_on=$claim['mil_on'] ?? null; unset($s->vehicle_data);
            $shift=$canSeeShifts ? $shiftsByVehicle->get($s->id) : null;
            $s->current_shift_id=$shift->id ?? null;
            $s->current_driver_id=$shift->driver_id ?? null;
            $s->current_driver_name=$shift->driver_name ?? null;
            $s->current_shift_started_at=$shift->started_at ?? null;
            $s->current_shift_expires_at=$shift->attribution_expires_at ?? null;
        }
        return ApiResponse::success($request,['items'=>$page->items(),'pagination'=>['next_cursor'=>$page->nextCursor()?->encode(),'per_page'=>$page->perPage()]],'Fleet States Retrieved');
    }
    public function dashboard(Request $request, string $organization)
    {
        $this->authorize($request,'dashboard.read');
        $vehicles=DB::table('vehicles')->where('organization_id',$organization)->where('status','<>','archived')->count();
        return ApiResponse::success($request,['as_of'=>now('UTC')->toIso8601String(),'vehicles'=>$vehicles,
            'observed_vehicles'=>DB::table('vehicle_latest_states as l')->join('vehicles as v','v.id','=','l.vehicle_id')->where('l.organization_id',$organization)->where('v.status','<>','archived')->count(),
            'active_shifts'=>DB::table('driver_shifts')->where('organization_id',$organization)->whereNull('ended_at')->where('attribution_expires_at','>',now())->count(),
            'active_automatic_journeys'=>DB::table('journeys')->where('organization_id',$organization)->where('state','active')->count(),
            'open_alerts'=>DB::table('alert_occurrences')->where('organization_id',$organization)->where('state','open')->count(),
            'pending_telemetry'=>DB::table('telemetry_inbox')->where('organization_id',$organization)->whereNull('projected_at')->count(),
            'journeys_are_not_fares'=>true], 'Fleet Dashboard Retrieved');
    }
}
