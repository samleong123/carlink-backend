<?php
namespace App\Http\Controllers\Api\V1;
use App\Services\Entitlements;
use App\Services\Permissions;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class DriverSelfController
{
    private function driver(Request $r, string $org): object
    {
        abort_unless(app(Permissions::class)->allows($r->attributes->get('membership'),'profile.own'),403);
        return DB::table('drivers')->where('organization_id',$org)->where('user_id',$r->user()->id)->where('status','active')->first() ?? abort(404);
    }
    private function shifts(string $org, string $driver) { return DB::table('driver_shifts')->where('organization_id',$org)->where('driver_id',$driver)->select('id'); }
    private function samples(string $org, string $driver)
    {
        return DB::table('telemetry_samples')->where('telemetry_samples.organization_id',$org)->whereIn('telemetry_samples.authorized_shift_id',$this->shifts($org,$driver))->where('telemetry_samples.occurred_at','>=',app(Entitlements::class)->cutoff($org));
    }
    public function profile(Request $r, string $organization) { $d=$this->driver($r,$organization); unset($d->photo_path); return ApiResponse::success($r,['driver'=>$d],'Own Driver Profile Retrieved'); }
    public function records(Request $r, string $organization)
    {
        $d=$this->driver($r,$organization);
        $v=$r->validate([
            'from'=>'nullable|date',
            'to'=>'nullable|date',
            'vehicle_id'=>'nullable|uuid',
            'per_page'=>'nullable|integer|min:1|max:200',
            'cursor'=>'nullable|string|max:512',
        ]);
        $p=$this->samples($organization,$d->id)
            ->leftJoin('vehicles as v',fn($j)=>$j->on('v.id','=','telemetry_samples.vehicle_id')->on('v.organization_id','=','telemetry_samples.organization_id'))
            ->when($v['from'] ?? null,fn($q,$from)=>$q->where('telemetry_samples.occurred_at','>=',CarbonImmutable::parse($from)))
            ->when($v['to'] ?? null,fn($q,$to)=>$q->where('telemetry_samples.occurred_at','<=',CarbonImmutable::parse($to)))
            ->when($v['vehicle_id'] ?? null,fn($q,$veh)=>$q->where('telemetry_samples.vehicle_id',$veh))
            ->select([
                'telemetry_samples.id',
                'telemetry_samples.vehicle_id',
                'v.registration_number',
                'telemetry_samples.occurred_at',
                'telemetry_samples.speed_kmh',
                'telemetry_samples.engine_rpm',
                'telemetry_samples.latitude',
                'telemetry_samples.longitude',
                'telemetry_samples.fuel_rate_lph',
                'telemetry_samples.quality',
            ])
            ->orderByDesc('telemetry_samples.occurred_at')
            ->orderByDesc('telemetry_samples.id')
            ->cursorPaginate($v['per_page'] ?? 50);
        return ApiResponse::success($r,['items'=>$p->items(),'pagination'=>['next_cursor'=>$p->nextCursor()?->encode(),'previous_cursor'=>$p->previousCursor()?->encode(),'per_page'=>$p->perPage()]],'Own Driving Records Retrieved');
    }
    public function score(Request $r, string $organization)
    {
        $d=$this->driver($r,$organization); app(Entitlements::class)->feature($organization,'safety');
        return ApiResponse::success($r,app(SafetyController::class)->scoreData($organization,$d->id),'Own Safety Score Retrieved');
    }
    public function events(Request $r, string $organization)
    {
        $d=$this->driver($r,$organization); app(Entitlements::class)->feature($organization,'safety');
        $v=$r->validate(['per_page'=>'nullable|integer|min:1|max:100','cursor'=>'nullable|string|max:512']);
        $p=DB::table('safety_events')
            ->join('telemetry_samples as end_s','end_s.id','=','safety_events.end_sample_id')
            ->join('telemetry_samples as start_s','start_s.id','=','safety_events.start_sample_id')
            ->leftJoin('vehicles as v',fn($j)=>$j->on('v.id','=','safety_events.vehicle_id')->on('v.organization_id','=','safety_events.organization_id'))
            ->where('safety_events.organization_id',$organization)
            ->where('safety_events.driver_id',$d->id)
            ->where('safety_events.occurred_at','>=',app(Entitlements::class)->cutoff($organization))
            ->where('safety_events.state','<>','superseded')
            ->select([
                'safety_events.*',
                DB::raw('COALESCE(end_s.latitude, start_s.latitude) as latitude'),
                DB::raw('COALESCE(end_s.longitude, start_s.longitude) as longitude'),
                'start_s.latitude as start_latitude',
                'start_s.longitude as start_longitude',
                'end_s.latitude as end_latitude',
                'end_s.longitude as end_longitude',
                'start_s.speed_kmh as start_speed_kmh',
                'end_s.speed_kmh as end_speed_kmh',
                DB::raw('CASE WHEN end_s.speed_kmh IS NOT NULL AND start_s.speed_kmh IS NOT NULL THEN (end_s.speed_kmh - start_s.speed_kmh) ELSE NULL END as delta_speed_kmh'),
                'v.registration_number',
                'v.make as vehicle_make',
                'v.model as vehicle_model',
            ])
            ->orderBy('safety_events.id')
            ->cursorPaginate($v['per_page'] ?? 50);
        return ApiResponse::success($r,['items'=>$p->items(),'pagination'=>['next_cursor'=>$p->nextCursor()?->encode()]],'Own Driving Events Retrieved');
    }
    public function appeal(Request $r, string $organization)
    {
        $d=$this->driver($r,$organization); app(Entitlements::class)->feature($organization,'safety');
        $v=$r->validate(['event_id'=>'required|uuid','reason'=>'required|string|max:2000']);
        return DB::transaction(function () use ($r,$organization,$d,$v) {
            DB::table('organizations')->where('id',$organization)->lockForUpdate()->first();
            $event=DB::table('safety_events')->where('organization_id',$organization)->where('driver_id',$d->id)->where('id',$v['event_id'])->where('occurred_at','>=',app(Entitlements::class)->cutoff($organization))->first() ?? abort(404);
            abort_if($event->state !== 'observed' || DB::table('safety_appeals')->where('event_id',$event->id)->exists(),409);
            $id=(string)Str::uuid(); DB::table('safety_appeals')->insert(['id'=>$id,'organization_id'=>$organization,'event_id'=>$event->id,'driver_id'=>$d->id,'submitted_by'=>$r->user()->id,'reason'=>$v['reason'],'created_at'=>now(),'updated_at'=>now()]);
            app(\App\Services\Mail\NotificationEmails::class)->queue('appeal.submitted',$id,$r->user()->id,$organization,'Your appeal has been submitted','Your driving observation appeal is awaiting a compliance-authorized reviewer.',
                ['Appeal reference'=>$id,'Observation'=>$event->kind,'Your statement'=>$v['reason']],['permissions'=>['profile.own'],'driver_id'=>$d->id,'safety_required'=>true,'subscription_required'=>false,'period_start'=>$event->occurred_at]);
            app(\App\Services\Mail\NotificationEmails::class)->organization('appeal.review_requested',$id,$organization,'A driving appeal needs review','A driver submitted an appeal. Review the authorized record in CarLink.',
                ['Appeal reference'=>$id],['safety.appeals.decide'],['safety_required'=>true,'subscription_required'=>false,'period_start'=>$event->occurred_at,'resource_type'=>'appeal','resource_id'=>$id]);
            return ApiResponse::success($r,['appeal_id'=>$id],'Appeal Submitted','',201);
        });
    }
    public function appeals(Request $r, string $organization)
    {
        $d=$this->driver($r,$organization);
        $p=DB::table('safety_appeals')->where('organization_id',$organization)->where('driver_id',$d->id)->where('created_at','>=',app(Entitlements::class)->cutoff($organization))->orderBy('id')->cursorPaginate(50);
        return ApiResponse::success($r,['items'=>$p->items(),'pagination'=>['next_cursor'=>$p->nextCursor()?->encode()]],'Own Appeals Retrieved');
    }
    public function energy(Request $r, string $organization)
    {
        $d=$this->driver($r,$organization); $maxGap=app(Entitlements::class)->maxCompatibleGapSeconds($organization);
        $rawSettings = DB::table('organization_settings')->where('organization_id', $organization)->value('settings');
        $orgSettings = $rawSettings ? (json_decode($rawSettings, true) ?: []) : [];
        $vehicleFuelTypes = DB::table('vehicles')->where('organization_id', $organization)->pluck('fuel_type', 'id')->all();
        $fuel=0.0; $metres=0.0; $fuelSeconds=0.0; $distanceSeconds=0.0; $alignedFuel=0.0; $alignedMetres=0.0; $alignedSeconds=0.0;
        $co2Kg = 0.0; $co2Known = true; $factorLabels = [];
        $previous=null;
        foreach ($this->samples($organization,$d->id)->orderBy('occurred_at')->orderBy('id')->cursor() as $s) {
            if ($previous && $s->authorized_shift_id === $previous->authorized_shift_id && $s->installation_id === $previous->installation_id && $s->source_format === $previous->source_format) {
                $dt=CarbonImmutable::parse($previous->occurred_at)->diffInSeconds(CarbonImmutable::parse($s->occurred_at),false);
                if ($dt>0 && $dt<=$maxGap) {
                    if ($s->speed_kmh !== null && $previous->speed_kmh !== null) { $metres+=((float)$s->speed_kmh+(float)$previous->speed_kmh)*$dt/7.2; $distanceSeconds+=$dt; }
                    if ($s->fuel_rate_lph !== null && $previous->fuel_rate_lph !== null && $s->fuel_rate_source === $previous->fuel_rate_source) {
                        $intervalFuel = ((float)$s->fuel_rate_lph+(float)$previous->fuel_rate_lph)*$dt/7200;
                        $fuel += $intervalFuel; $fuelSeconds += $dt;
                        $factor = \App\Support\EmissionFactors::resolve($organization, $vehicleFuelTypes[$s->vehicle_id] ?? 'petrol', $orgSettings);
                        $factorLabels[$factor['label']] = true;
                        if ($factor['kg_per_litre'] === null) { $co2Known = false; }
                        else { $co2Kg += $intervalFuel * $factor['kg_per_litre']; }
                    }
                    if ($s->speed_kmh !== null && $previous->speed_kmh !== null && $s->fuel_rate_lph !== null && $previous->fuel_rate_lph !== null && $s->fuel_rate_source === $previous->fuel_rate_source) {
                        $alignedMetres+=((float)$s->speed_kmh+(float)$previous->speed_kmh)*$dt/7.2; $alignedFuel+=((float)$s->fuel_rate_lph+(float)$previous->fuel_rate_lph)*$dt/7200; $alignedSeconds+=$dt;
                    }
                }
            }
            $previous=$s;
        }
        return ApiResponse::success($r,['distance_km'=>$distanceSeconds>0 ? round($metres/1000,6) : null,'fuel_litres'=>$fuelSeconds>0 ? round($fuel,6) : null,
            'estimated_co2_kg'=>($fuelSeconds>0 && $co2Known) ? round($co2Kg,6) : null,
            'emission_factor'=>$factorLabels !== [] ? implode('; ', array_keys($factorLabels)) : \App\Support\EmissionFactors::resolve($organization, 'petrol', $orgSettings)['label'],
            'litres_per_100km'=>$alignedSeconds>0 && $alignedMetres>0 ? round($alignedFuel*100000/$alignedMetres,6) : null,'efficiency_basis'=>'only_aligned_covered_intervals','aligned_covered_seconds'=>$alignedSeconds,'distance_covered_seconds'=>$distanceSeconds,'fuel_covered_seconds'=>$fuelSeconds,'max_compatible_gap_seconds'=>$maxGap,'quality'=>'partial_snapshot_estimate','allocation'=>'both_endpoints_authorized_to_same_driver_shift'],'Own Fuel and Distance Retrieved');
    }
    public function trips(Request $r, string $organization)
    {
        $d=$this->driver($r,$organization); $cut=app(Entitlements::class)->cutoff($organization);
        $v=$r->validate(['per_page'=>'nullable|integer|min:1|max:100','cursor'=>'nullable|string|max:2048']); $cursor=null;
        if (isset($v['cursor']) && $v['cursor'] !== '') {
            try {
                $decoded=json_decode(Crypt::decryptString($v['cursor']),true,16,JSON_THROW_ON_ERROR);
                if ($decoded['organization'] !== $organization || $decoded['driver'] !== $d->id) throw new \RuntimeException();
                $cursor=\App\Support\CursorPage::decode($decoded['cursor'],['first_own_observation_at','shift_id','journey_id']);
            } catch (\Throwable $e) { throw ValidationException::withMessages(['cursor'=>['Invalid own-trip cursor.']]); }
        }
        // Aggregate only authorized observations, not full journey/shift bounds.
        // No pre-pagination cap on shifts or journeys; tie-breakers stay encrypted.
        $segments=DB::table('telemetry_samples as s')
            ->join('driver_shifts as sh',fn($j)=>$j->on('sh.id','=','s.authorized_shift_id')->on('sh.organization_id','=','s.organization_id')->on('sh.vehicle_id','=','s.vehicle_id')->on('sh.device_installation_id','=','s.installation_id'))
            ->join('journeys as j',fn($j)=>$j->on('j.organization_id','=','s.organization_id')->on('j.vehicle_id','=','s.vehicle_id')->on('s.occurred_at','>=','j.started_at')->on('s.occurred_at','<=','j.last_observed_at'))
            ->where('s.organization_id',$organization)->where('sh.driver_id',$d->id)->where('s.occurred_at','>=',$cut)->where('s.occurred_at','<=',now())
            ->whereColumn('s.occurred_at','>=','sh.started_at')->whereColumn('s.occurred_at','<=','sh.attribution_expires_at')->whereRaw('s.occurred_at <= COALESCE(sh.ended_at, sh.attribution_expires_at)')
            ->groupBy('sh.id','j.id','s.vehicle_id')->selectRaw('sh.id as shift_id, j.id as journey_id, s.vehicle_id, MIN(s.occurred_at) as first_own_observation_at, MAX(s.occurred_at) as last_own_observation_at');
        $page=DB::query()->fromSub($segments,'segments')
            ->leftJoin('vehicles as v',fn($j)=>$j->on('v.id','=','segments.vehicle_id')->where('v.organization_id','=',$organization))
            ->select(['segments.*','v.registration_number','v.make','v.model'])
            ->orderByDesc('first_own_observation_at')->orderByDesc('shift_id')->orderByDesc('journey_id')
            ->cursorPaginate($v['per_page'] ?? 50,['*'],'cursor',$cursor);
        $encode=fn($c)=>$c ? Crypt::encryptString(json_encode(['organization'=>$organization,'driver'=>$d->id,'cursor'=>$c->encode()],JSON_THROW_ON_ERROR)) : null;
        $items=array_map(fn($s)=>[
            'segment_id'=>hash('sha256',$s->journey_id.$s->shift_id),
            'vehicle_id'=>$s->vehicle_id,
            'registration_number'=>$s->registration_number,
            'make'=>$s->make,
            'model'=>$s->model,
            'first_own_observation_at'=>$s->first_own_observation_at,
            'last_own_observation_at'=>$s->last_own_observation_at,
            'quality'=>'own_observed_journey_intersection',
            'is_fare'=>false,
        ],$page->items());
        return ApiResponse::success($r,['items'=>$items,'pagination'=>['next_cursor'=>$encode($page->nextCursor()),'previous_cursor'=>$encode($page->previousCursor()),'per_page'=>$page->perPage()],'shared_journey_bounds_hidden'=>true],'Own Trip Segments Retrieved');
    }
    public function tripDetail(Request $r, string $organization, string $segment)
    {
        $d=$this->driver($r,$organization);
        abort_unless(is_string($segment) && preg_match('/^[0-9a-f]{64}$/i',$segment),404,'Invalid trip segment identifier.');

        $cut=app(Entitlements::class)->cutoff($organization);

        $candidateSegments=DB::table('telemetry_samples as s')
            ->join('driver_shifts as sh',fn($j)=>$j->on('sh.id','=','s.authorized_shift_id')->on('sh.organization_id','=','s.organization_id')->on('sh.vehicle_id','=','s.vehicle_id')->on('sh.device_installation_id','=','s.installation_id'))
            ->join('journeys as j',fn($j)=>$j->on('j.organization_id','=','s.organization_id')->on('j.vehicle_id','=','s.vehicle_id')->on('s.occurred_at','>=','j.started_at')->on('s.occurred_at','<=','j.last_observed_at'))
            ->where('s.organization_id',$organization)->where('sh.driver_id',$d->id)->where('s.occurred_at','>=',$cut)->where('s.occurred_at','<=',now())
            ->whereColumn('s.occurred_at','>=','sh.started_at')->whereColumn('s.occurred_at','<=','sh.attribution_expires_at')->whereRaw('s.occurred_at <= COALESCE(sh.ended_at, sh.attribution_expires_at)')
            ->groupBy('sh.id','j.id','s.vehicle_id')->selectRaw('sh.id as shift_id, j.id as journey_id, s.vehicle_id, MIN(s.occurred_at) as first_own_observation_at, MAX(s.occurred_at) as last_own_observation_at')
            ->get();

        $targetSegment=null;
        $segLower=strtolower($segment);
        foreach ($candidateSegments as $candidate) {
            if (hash_equals($segLower,hash('sha256',$candidate->journey_id.$candidate->shift_id))) {
                $targetSegment=$candidate;
                break;
            }
        }
        abort_unless($targetSegment !== null,404,'Trip segment not found.');

        $vehicle=DB::table('vehicles')->where('organization_id',$organization)->where('id',$targetSegment->vehicle_id)->first(['id','registration_number','make','model']);

        $samplesRows=DB::table('telemetry_samples as s')
            ->join('driver_shifts as sh',fn($j)=>$j->on('sh.id','=','s.authorized_shift_id')->on('sh.organization_id','=','s.organization_id')->on('sh.vehicle_id','=','s.vehicle_id')->on('sh.device_installation_id','=','s.installation_id'))
            ->join('journeys as j',fn($j)=>$j->on('j.organization_id','=','s.organization_id')->on('j.vehicle_id','=','s.vehicle_id')->on('s.occurred_at','>=','j.started_at')->on('s.occurred_at','<=','j.last_observed_at'))
            ->where('s.organization_id',$organization)->where('sh.id',$targetSegment->shift_id)->where('sh.driver_id',$d->id)
            ->where('j.id',$targetSegment->journey_id)->where('s.vehicle_id',$targetSegment->vehicle_id)
            ->where('s.occurred_at','>=',$cut)->where('s.occurred_at','<=',now())
            ->whereColumn('s.occurred_at','>=','sh.started_at')->whereColumn('s.occurred_at','<=','sh.attribution_expires_at')->whereRaw('s.occurred_at <= COALESCE(sh.ended_at, sh.attribution_expires_at)')
            ->orderBy('s.occurred_at')->orderBy('s.id')
            ->select(['s.id','s.vehicle_id','s.occurred_at','s.speed_kmh','s.engine_rpm','s.latitude','s.longitude','s.fuel_rate_lph','s.quality'])
            ->get();

        $maxGap=app(Entitlements::class)->maxCompatibleGapSeconds($organization);
        $metres=0.0;
        $previous=null;
        $samples=[];
        foreach ($samplesRows as $s) {
            if ($previous) {
                $dt=CarbonImmutable::parse($previous->occurred_at)->diffInSeconds(CarbonImmutable::parse($s->occurred_at),false);
                if ($dt>0 && $dt<=$maxGap) {
                    if ($s->speed_kmh !== null && $previous->speed_kmh !== null) {
                        $metres+=((float)$s->speed_kmh+(float)$previous->speed_kmh)*$dt/7.2;
                    }
                }
            }
            $previous=$s;

            $samples[]=[
                'id'=>$s->id,
                'vehicle_id'=>$s->vehicle_id,
                'occurred_at'=>$s->occurred_at,
                'speed_kmh'=>$s->speed_kmh !== null ? (float)$s->speed_kmh : null,
                'engine_rpm'=>$s->engine_rpm !== null ? (float)$s->engine_rpm : null,
                'latitude'=>$s->latitude !== null ? (float)$s->latitude : null,
                'longitude'=>$s->longitude !== null ? (float)$s->longitude : null,
                'fuel_rate_lph'=>$s->fuel_rate_lph !== null ? (float)$s->fuel_rate_lph : null,
                'quality'=>is_string($s->quality) ? (json_decode($s->quality,true) ?? $s->quality) : $s->quality,
            ];
        }

        $distanceKmEstimate=round($metres/1000,3);
        if ($metres == 0.0 && count($samplesRows)>1) {
            $gpsMetres=0.0;
            $prevGps=null;
            foreach ($samplesRows as $s) {
                if ($s->latitude !== null && $s->longitude !== null) {
                    if ($prevGps !== null) {
                        $lat1=deg2rad((float)$prevGps->latitude);
                        $lon1=deg2rad((float)$prevGps->longitude);
                        $lat2=deg2rad((float)$s->latitude);
                        $lon2=deg2rad((float)$s->longitude);
                        $dlat=$lat2-$lat1;
                        $dlon=$lon2-$lon1;
                        $a=sin($dlat/2)**2+cos($lat1)*cos($lat2)*sin($dlon/2)**2;
                        $c=2*atan2(sqrt($a),sqrt(1-$a));
                        $gpsMetres+=6371000*$c;
                    }
                    $prevGps=$s;
                }
            }
            if ($gpsMetres>0) {
                $distanceKmEstimate=round($gpsMetres/1000,3);
            }
        }

        $safetyEnabled = !config('saas.enforce_subscriptions') || (
            app(Entitlements::class)->active($organization) &&
            in_array(app(Entitlements::class)->plan($organization)['safety_enabled'] ?? false, [true, 1], true)
        );

        $safetyEvents=[];
        if ($safetyEnabled) {
            $safetyEvents=DB::table('safety_events')
                ->join('telemetry_samples as end_s','end_s.id','=','safety_events.end_sample_id')
                ->join('telemetry_samples as start_s','start_s.id','=','safety_events.start_sample_id')
                ->leftJoin('vehicles as v',fn($j)=>$j->on('v.id','=','safety_events.vehicle_id')->on('v.organization_id','=','safety_events.organization_id'))
                ->where('safety_events.organization_id',$organization)
                ->where('safety_events.driver_id',$d->id)
                ->where('safety_events.shift_id',$targetSegment->shift_id)
                ->where('safety_events.occurred_at','>=',$cut)
                ->where('safety_events.occurred_at','>=',$targetSegment->first_own_observation_at)
                ->where('safety_events.occurred_at','<=',$targetSegment->last_own_observation_at)
                ->where('safety_events.state','<>','superseded')
                ->select([
                    'safety_events.*',
                    DB::raw('COALESCE(end_s.latitude, start_s.latitude) as latitude'),
                    DB::raw('COALESCE(end_s.longitude, start_s.longitude) as longitude'),
                    'start_s.latitude as start_latitude',
                    'start_s.longitude as start_longitude',
                    'end_s.latitude as end_latitude',
                    'end_s.longitude as end_longitude',
                    'start_s.speed_kmh as start_speed_kmh',
                    'end_s.speed_kmh as end_speed_kmh',
                    DB::raw('CASE WHEN end_s.speed_kmh IS NOT NULL AND start_s.speed_kmh IS NOT NULL THEN (end_s.speed_kmh - start_s.speed_kmh) ELSE NULL END as delta_speed_kmh'),
                    'v.registration_number',
                    'v.make as vehicle_make',
                    'v.model as vehicle_model',
                ])
                ->orderBy('safety_events.occurred_at')->orderBy('safety_events.id')
                ->get();
        }

        return ApiResponse::success($r,[
            'segment'=>[
                'segment_id'=>$segment,
                'vehicle_id'=>$targetSegment->vehicle_id,
                'registration_number'=>$vehicle?->registration_number,
                'make'=>$vehicle?->make,
                'model'=>$vehicle?->model,
                'first_own_observation_at'=>$targetSegment->first_own_observation_at,
                'last_own_observation_at'=>$targetSegment->last_own_observation_at,
                'sample_count'=>count($samples),
                'distance_km_estimate'=>$distanceKmEstimate,
                'quality'=>'own_observed_journey_intersection',
                'is_fare'=>false,
            ],
            'samples'=>$samples,
            'safety_events'=>$safetyEvents,
        ],'Own Trip Detail Retrieved');
    }
    public function vehicleInfo(Request $r, string $organization)
    {
        $d=$this->driver($r,$organization);
        $shift=DB::table('driver_shifts')->where('organization_id',$organization)->where('driver_id',$d->id)->where('state','active')->whereNull('ended_at')->where('attribution_expires_at','>',now())->first();
        if (!$shift) return ApiResponse::success($r,['vehicle'=>null,'maintenance'=>[],'compliance'=>[]],'No Currently Authorized Vehicle');
        $vehicle=DB::table('vehicles')->where('organization_id',$organization)->where('id',$shift->vehicle_id)->first(['id','registration_number','make','model']);
        $plans=DB::table('vehicle_maintenance_plans')->where('organization_id',$organization)->where('vehicle_id',$shift->vehicle_id)->get();
        $obligations=DB::table('compliance_obligations')->where('organization_id',$organization)->where('vehicle_id',$shift->vehicle_id)->get();
        $compliance=$obligations->map(fn($o)=>['obligation_id'=>$o->id]+app(ComplianceController::class)->evaluate($organization,$o));
        return ApiResponse::success($r,['vehicle'=>$vehicle,'maintenance'=>$plans,'compliance'=>$compliance],'Own Current Vehicle Compliance Retrieved');
    }
}
