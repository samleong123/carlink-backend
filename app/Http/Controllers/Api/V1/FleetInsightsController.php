<?php
namespace App\Http\Controllers\Api\V1;
use App\Services\DeviceCommands;
use App\Services\Entitlements;
use App\Services\Permissions;
use App\Support\ApiResponse;
use App\Support\EmissionFactors;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FleetInsightsController
{
    private function allow(Request $r, string $key): void { abort_unless(app(Permissions::class)->allows($r->attributes->get('membership'),$key),403); }
    public function drivers(Request $r, string $organization)
    {
        $this->allow($r,'drivers.read');
        [$size,$cursor]=\App\Support\CursorPage::options($r,200,['d.id']);
        $page=DB::table('drivers as d')
            ->leftJoin('driver_shifts as sh',fn($j)=>$j->on('sh.driver_id','=','d.id')->on('sh.organization_id','=','d.organization_id')->where('sh.state','active')->whereNull('sh.ended_at')->where('sh.attribution_expires_at','>',now()))
            ->leftJoin('vehicles as v','v.id','=','sh.vehicle_id')
            ->leftJoin('vehicle_latest_states as l',fn($j)=>$j->on('l.vehicle_id','=','v.id')->on('l.organization_id','=','d.organization_id'))
            ->leftJoin('telemetry_samples as s',fn($j)=>$j->on('s.id','=','l.sample_id')->where(fn($q)=>$q->whereColumn('s.authorized_shift_id','sh.id')->orWhereColumn('s.occurred_at','>=','sh.started_at')))
            ->where('d.organization_id',$organization)
            ->orderBy('d.id')
            ->cursorPaginate($size,[
                'd.id','d.name','d.status','sh.id as shift_id',
                'v.id as vehicle_id','v.registration_number','v.make','v.model',
                's.latitude','s.longitude','s.speed_kmh','s.engine_rpm','s.occurred_at','s.vehicle_data',
                'sh.started_at as shift_started_at','sh.attribution_expires_at',
            ],'cursor',$cursor);

        $settings = json_decode(DB::table('organization_settings')->where('organization_id', $organization)->value('settings') ?? '{}', true) ?: [];
        $offlineAfter = (int) ($settings['device_offline_after_s'] ?? 180);
        $privacy = app(\App\Services\DtcPrivacy::class);
        $cutoff = CarbonImmutable::now('UTC')->subSeconds($offlineAfter);

        foreach ($page->items() as $item) {
            $claim = $item->vehicle_data ? $privacy->scrub(json_decode($item->vehicle_data, true, 32, JSON_THROW_ON_ERROR)) : [];
            $codes = is_array($claim['dtc_codes'] ?? null) ? array_values($claim['dtc_codes']) : [];
            $item->current_driver_name = $item->vehicle_id ? $item->name : null;
            $item->driver_name = $item->name;
            $item->freshness = $item->occurred_at
                ? (CarbonImmutable::parse($item->occurred_at)->lt($cutoff) ? 'stale' : 'recent_packet')
                : ($item->vehicle_id ? 'never_observed' : null);
            $item->has_fault_code = $item->vehicle_id ? (($claim['has_dtc_fault'] ?? null) || count($codes) > 0) : null;
            $item->dtc_codes = $codes;
            $item->mil_on = $item->vehicle_id ? ($claim['mil_on'] ?? null) : null;
            unset($item->vehicle_data);
        }

        return ApiResponse::success($r,['items'=>$page->items(),'pagination'=>\App\Support\CursorPage::metadata($page)],'Driver Assignments Retrieved','Locations require currently authorized NFC shifts; old observations are not live GPS.');
    }
    public function coverage(Request $r, string $organization)
    {
        $this->allow($r,$r->boolean('export') ? 'coverage.export' : 'coverage.read'); app(Entitlements::class)->feature($organization,'cellular');
        $v=$r->validate(['from'=>'required|date','to'=>'required|date|after:from','per_page'=>'nullable|integer|min:1|max:200','cursor'=>'nullable|string|max:512','export'=>'nullable|boolean']);
        $from=\Carbon\CarbonImmutable::parse($v['from']); $to=\Carbon\CarbonImmutable::parse($v['to']); abort_if($from->diffInSeconds($to)>86400,422);
        $q=DB::table('telemetry_samples as s')
            ->leftJoin('vehicles as v',fn($j)=>$j->on('v.id','=','s.vehicle_id')->on('v.organization_id','=','s.organization_id'))
            ->where('s.organization_id',$organization)->where('s.occurred_at','>=',$from)->where('s.occurred_at','<',$to)->orderBy('s.id');
        if ($r->boolean('export')) {
            $rows=$q->limit(10001)->get([
                's.id','s.vehicle_id','v.registration_number','v.make as vehicle_make','v.model as vehicle_model',
                's.occurred_at','s.received_at','s.latitude','s.longitude','s.position_observed_at',
                's.speed_kmh','s.engine_rpm','s.quality','s.source_format','s.network_data',
            ]);
            abort_if(count($rows)>10000,422,'Narrow the export window to at most 10000 samples.');
            $headers=[
                'sample_id','vehicle_id','registration_number','vehicle_make','vehicle_model',
                'occurred_at','received_at','latitude','longitude','position_observed_at',
                'speed_kmh','engine_rpm','gps_quality','operator','registered_plmn','mcc','mnc',
                'network_mode','registration_status','roaming','connected','cell_id','tac',
                'lte_band','earfcn','pci','rsrp_dbm','rsrq_db','sinr_db','rssi_dbm',
                'modem_model','source_format',
            ];
            $formatBool=static fn($val)=>is_bool($val) ? ($val ? 'true' : 'false') : null;
            $f=fopen('php://temp','r+'); fputcsv($f,$headers,escape:'');
            foreach ($rows as $s) {
                $n=json_decode($s->network_data ?? '{}',true); if (!is_array($n)) $n=[];
                $quality=json_decode($s->quality ?? '{}',true); if (!is_array($quality)) $quality=[];
                $plmnRaw=$n['registered_plmn'] ?? null;
                $plmn=is_string($plmnRaw) || is_int($plmnRaw) ? trim((string)$plmnRaw) : null;
                $hasNumericPlmn=$plmn !== null && preg_match('/^\d{5,6}$/',$plmn) === 1;
                $values=[
                    $s->id,$s->vehicle_id,$s->registration_number,$s->vehicle_make,$s->vehicle_model,
                    $s->occurred_at,$s->received_at,$s->latitude,$s->longitude,$s->position_observed_at,
                    $s->speed_kmh,$s->engine_rpm,$quality['gps'] ?? null,
                    $n['operator'] ?? null,$plmn !== '' ? $plmn : null,$hasNumericPlmn ? substr($plmn,0,3) : null,$hasNumericPlmn ? substr($plmn,3) : null,
                    $n['network_type'] ?? null,$n['registration_status'] ?? null,$formatBool($n['roaming'] ?? null),$formatBool($n['connected'] ?? null),
                    $n['cell_id'] ?? null,$n['tac'] ?? null,$n['lte_band'] ?? null,$n['earfcn'] ?? null,$n['pci'] ?? null,
                    $n['rsrp_dbm'] ?? null,$n['rsrq_db'] ?? null,$n['sinr_db'] ?? null,$n['rssi_dbm'] ?? null,
                    $n['modem_model'] ?? null,$s->source_format,
                ];
                $values=array_map(fn($x)=>is_string($x) && preg_match('/^[=+@\t\r]|^-\D/',$x) ? "'".$x : $x,$values);
                fputcsv($f,$values,escape:'');
            }
            rewind($f); $csv=stream_get_contents($f); fclose($f);
            return ApiResponse::success($r,['filename'=>'coverage.csv','content_type'=>'text/csv','csv'=>$csv,'rows'=>count($rows)],'Coverage CSV Prepared','CSV is inside the standard JSON envelope; unknown signal values remain blank.');
        }
        $p=$q->cursorPaginate($v['per_page'] ?? 100,['s.id','s.vehicle_id','v.registration_number','v.make as vehicle_make','v.model as vehicle_model','s.occurred_at','s.latitude','s.longitude','s.network_data']);
        foreach ($p->items() as $s) $s->network_data=json_decode($s->network_data ?? '{}');
        return ApiResponse::success($r,['items'=>$p->items(),'pagination'=>['next_cursor'=>$p->nextCursor()?->encode()],'quality'=>'raw_observations_not_radio_survey'],'Fleet Coverage Retrieved');
    }
    public function report(Request $r, string $organization)
    {
        $this->allow($r,'reports.read');
        $entitlements = app(Entitlements::class);
        $cut = $entitlements->cutoff($organization);
        $tz = DB::table('organizations')->where('id', $organization)->value('timezone') ?: 'Asia/Kuala_Lumpur';
        $today = CarbonImmutable::now($tz)->startOfDay();

        // 1. Existing top-level keys
        $vehiclesCount = DB::table('vehicles')->where('organization_id', $organization)->where('status', '<>', 'archived')->count();
        $driversCount = DB::table('drivers')->where('organization_id', $organization)->count();
        $safetyObsCount = DB::table('safety_events')->where('organization_id', $organization)->where('occurred_at', '>=', $cut)->where('state', 'observed')->count();
        $openAlertsCount = DB::table('alert_occurrences')->where('organization_id', $organization)->where('state', 'open')->count();
        $complianceObligationsCount = DB::table('compliance_obligations')->where('organization_id', $organization)->count();

        // 2. fleet_summary
        $activeVehicles = DB::table('vehicles')->where('organization_id', $organization)->where('status', 'active')->count();
        $inactiveMaintVehicles = DB::table('vehicles')->where('organization_id', $organization)->whereIn('status', ['inactive', 'maintenance'])->count();
        $activeDrivers = DB::table('drivers')->where('organization_id', $organization)->where('status', 'active')->count();
        $inactiveDrivers = DB::table('drivers')->where('organization_id', $organization)->where('status', '<>', 'active')->count();
        $activeShifts = DB::table('driver_shifts')->where('organization_id', $organization)->where('state', 'active')->count();
        $activeJourneys = DB::table('journeys')->where('organization_id', $organization)->where('state', 'active')->count();
        $totalJourneysWindow = DB::table('journeys')->where('organization_id', $organization)->where('started_at', '>=', $cut)->count();

        $distanceM = DB::table('vehicle_distance_segments')
            ->where('organization_id', $organization)
            ->whereNull('superseded_at')
            ->where('from_at', '>=', $cut)
            ->whereNotNull('distance_m')
            ->sum('distance_m');
        $distanceKm30d = round(((float)$distanceM) / 1000.0, 2);

        $energySegments = DB::table('vehicle_energy_segments as e')
            ->leftJoin('vehicles as v', fn($j) => $j->on('v.id', '=', 'e.vehicle_id')->on('v.organization_id', '=', 'e.organization_id'))
            ->where('e.organization_id', $organization)
            ->whereNull('e.superseded_at')
            ->where('e.from_at', '>=', $cut)
            ->whereNotNull('e.litres')
            ->select('e.vehicle_id', 'v.fuel_type', DB::raw('SUM(e.litres) as total_litres'))
            ->groupBy('e.vehicle_id', 'v.fuel_type')
            ->get();

        $fuelLitres30d = 0.0;
        $estimatedCo2Kg30d = 0.0;
        foreach ($energySegments as $seg) {
            $litres = (float) $seg->total_litres;
            $fuelLitres30d += $litres;
            $factor = EmissionFactors::resolve($organization, $seg->fuel_type);
            if ($factor['kg_per_litre'] !== null) {
                $estimatedCo2Kg30d += $litres * (float) $factor['kg_per_litre'];
            }
        }
        $fuelLitres30d = round($fuelLitres30d, 2);
        $estimatedCo2Kg30d = round($estimatedCo2Kg30d, 2);

        $fleetSummary = [
            'active_vehicles' => $activeVehicles,
            'inactive_or_maintenance_vehicles' => $inactiveMaintVehicles,
            'active_drivers' => $activeDrivers,
            'inactive_drivers' => $inactiveDrivers,
            'active_shifts' => $activeShifts,
            'active_journeys' => $activeJourneys,
            'total_journeys_in_window' => $totalJourneysWindow,
            'distance_km_30d' => $distanceKm30d,
            'fuel_litres_30d' => $fuelLitres30d,
            'estimated_co2_kg_30d' => $estimatedCo2Kg30d,
        ];

        // 3. 14-day window for daily_trend & daily_telemetry
        $trendBuckets = [];
        $telemetryBuckets = [];
        $windowStartUtc = null;
        for ($i = 13; $i >= 0; $i--) {
            $day = $today->subDays($i);
            if ($day->endOfDay()->setTimezone('UTC')->isBefore($cut)) {
                continue;
            }
            $dateStr = $day->toDateString();
            if ($windowStartUtc === null) {
                $windowStartUtc = $day->setTimezone('UTC');
            }
            $trendBuckets[$dateStr] = [
                'date' => $dateStr,
                'harsh_braking' => 0,
                'rapid_acceleration' => 0,
                'total' => 0,
            ];
            $telemetryBuckets[$dateStr] = [
                'date' => $dateStr,
                'samples' => 0,
                'distance_km' => 0.0,
                'fuel_litres' => 0.0,
            ];
        }
        if ($windowStartUtc === null) {
            $windowStartUtc = $cut;
        }

        // 4. safety_summary
        $plan = $entitlements->plan($organization);
        $safetyEnabled = !config('saas.enforce_subscriptions') || in_array($plan['safety_enabled'] ?? false, [true, 1, '1'], true);

        if (!$safetyEnabled) {
            $safetySummary = [
                'safety_enabled' => false,
                'average_safety_score' => null,
                'scored_drivers' => 0,
                'total_drivers' => $driversCount,
                'min_score' => null,
                'max_score' => null,
                'total_observed' => 0,
                'total_excluded' => 0,
                'harsh_braking_count' => 0,
                'rapid_acceleration_count' => 0,
                'pending_appeals' => 0,
                'approved_appeals' => 0,
                'rejected_appeals' => 0,
                'daily_trend' => [],
                'driver_scores' => [],
                'recent_harsh_events' => [],
            ];
        } else {
            $drivers = DB::table('drivers')->where('organization_id', $organization)->orderBy('name')->get();
            $safetyController = app(SafetyController::class);
            $driverScores = [];
            $scores = [];
            foreach ($drivers as $d) {
                $scoreData = $safetyController->scoreData($organization, $d->id);
                if ($scoreData['score'] !== null) {
                    $scores[] = (int) $scoreData['score'];
                }
                $driverScores[] = [
                    'driver_id' => $d->id,
                    'name' => $d->name,
                    'score' => $scoreData['score'] !== null ? (int) $scoreData['score'] : null,
                    'events_included' => (int) $scoreData['events_included'],
                    'eligible_speed_intervals' => (int) $scoreData['eligible_speed_intervals'],
                ];
            }
            usort($driverScores, fn($a, $b) => ($b['score'] ?? -1) <=> ($a['score'] ?? -1) ?: strcmp($a['name'], $b['name']));

            $scoredDrivers = count($scores);
            $averageSafetyScore = $scoredDrivers > 0 ? round(array_sum($scores) / $scoredDrivers, 1) : null;
            $minScore = $scoredDrivers > 0 ? min($scores) : null;
            $maxScore = $scoredDrivers > 0 ? max($scores) : null;

            $totalObserved = DB::table('safety_events')->where('organization_id', $organization)->where('occurred_at', '>=', $cut)->where('state', 'observed')->count();
            $totalExcluded = DB::table('safety_events')->where('organization_id', $organization)->where('occurred_at', '>=', $cut)->where('state', 'excluded')->count();
            $harshBrakingCount = DB::table('safety_events')->where('organization_id', $organization)->where('occurred_at', '>=', $cut)->where('state', '<>', 'superseded')->where('kind', 'harsh_braking')->count();
            $rapidAccelerationCount = DB::table('safety_events')->where('organization_id', $organization)->where('occurred_at', '>=', $cut)->where('state', '<>', 'superseded')->where('kind', 'rapid_acceleration')->count();

            $pendingAppeals = DB::table('safety_appeals')->where('organization_id', $organization)->where('created_at', '>=', $cut)->where('status', 'pending')->count();
            $approvedAppeals = DB::table('safety_appeals')->where('organization_id', $organization)->where('created_at', '>=', $cut)->where('status', 'approved')->count();
            $rejectedAppeals = DB::table('safety_appeals')->where('organization_id', $organization)->where('created_at', '>=', $cut)->where('status', 'rejected')->count();

            $safetyTrendRows = DB::table('safety_events')
                ->where('organization_id', $organization)
                ->where('occurred_at', '>=', $windowStartUtc)
                ->where('state', '<>', 'superseded')
                ->select('occurred_at', 'kind')
                ->get();

            foreach ($safetyTrendRows as $row) {
                $d = CarbonImmutable::parse($row->occurred_at)->setTimezone($tz)->toDateString();
                if (isset($trendBuckets[$d])) {
                    if ($row->kind === 'harsh_braking') {
                        $trendBuckets[$d]['harsh_braking']++;
                    } elseif ($row->kind === 'rapid_acceleration') {
                        $trendBuckets[$d]['rapid_acceleration']++;
                    }
                    $trendBuckets[$d]['total']++;
                }
            }

            $recentEvents = DB::table('safety_events as e')
                ->leftJoin('telemetry_samples as start_s', 'start_s.id', '=', 'e.start_sample_id')
                ->leftJoin('telemetry_samples as end_s', 'end_s.id', '=', 'e.end_sample_id')
                ->leftJoin('drivers as d', fn($j) => $j->on('d.id', '=', 'e.driver_id')->on('d.organization_id', '=', 'e.organization_id'))
                ->leftJoin('vehicles as v', fn($j) => $j->on('v.id', '=', 'e.vehicle_id')->on('v.organization_id', '=', 'e.organization_id'))
                ->where('e.organization_id', $organization)
                ->where('e.occurred_at', '>=', $cut)
                ->where('e.state', '<>', 'superseded')
                ->orderByDesc('e.occurred_at')
                ->orderByDesc('e.id')
                ->limit(50)
                ->get([
                    'e.id',
                    'e.organization_id',
                    'e.vehicle_id',
                    'e.driver_id',
                    'e.shift_id',
                    'e.start_sample_id',
                    'e.end_sample_id',
                    'e.kind',
                    'e.acceleration_ms2',
                    'e.threshold_ms2',
                    'e.occurred_at',
                    'e.quality',
                    'e.state',
                    'e.created_at',
                    'e.updated_at',
                    'start_s.latitude as start_latitude',
                    'start_s.longitude as start_longitude',
                    'end_s.latitude as end_latitude',
                    'end_s.longitude as end_longitude',
                    'start_s.speed_kmh as start_speed_kmh',
                    'end_s.speed_kmh as end_speed_kmh',
                    'd.name as driver_name',
                    'v.registration_number',
                    'v.make as vehicle_make',
                    'v.model as vehicle_model',
                ])
                ->map(function ($ev) {
                    $startLat = $ev->start_latitude !== null ? (float) $ev->start_latitude : null;
                    $startLon = $ev->start_longitude !== null ? (float) $ev->start_longitude : null;
                    $endLat = $ev->end_latitude !== null ? (float) $ev->end_latitude : null;
                    $endLon = $ev->end_longitude !== null ? (float) $ev->end_longitude : null;
                    $lat = $endLat ?? $startLat;
                    $lon = $endLon ?? $startLon;
                    $startSpeed = $ev->start_speed_kmh !== null ? (float) $ev->start_speed_kmh : null;
                    $endSpeed = $ev->end_speed_kmh !== null ? (float) $ev->end_speed_kmh : null;
                    $deltaSpeed = ($startSpeed !== null && $endSpeed !== null) ? round($endSpeed - $startSpeed, 2) : null;

                    return [
                        'id' => $ev->id,
                        'organization_id' => $ev->organization_id,
                        'vehicle_id' => $ev->vehicle_id,
                        'driver_id' => $ev->driver_id,
                        'shift_id' => $ev->shift_id,
                        'start_sample_id' => $ev->start_sample_id,
                        'end_sample_id' => $ev->end_sample_id,
                        'kind' => $ev->kind,
                        'acceleration_ms2' => (float) $ev->acceleration_ms2,
                        'threshold_ms2' => (float) $ev->threshold_ms2,
                        'occurred_at' => $ev->occurred_at,
                        'quality' => $ev->quality,
                        'state' => $ev->state,
                        'latitude' => $lat,
                        'longitude' => $lon,
                        'start_latitude' => $startLat,
                        'start_longitude' => $startLon,
                        'end_latitude' => $endLat,
                        'end_longitude' => $endLon,
                        'start_speed_kmh' => $startSpeed,
                        'end_speed_kmh' => $endSpeed,
                        'delta_speed_kmh' => $deltaSpeed,
                        'driver_name' => $ev->driver_name,
                        'registration_number' => $ev->registration_number,
                        'vehicle_make' => $ev->vehicle_make,
                        'vehicle_model' => $ev->vehicle_model,
                        'created_at' => $ev->created_at,
                        'updated_at' => $ev->updated_at,
                    ];
                })
                ->values()
                ->all();

            $safetySummary = [
                'safety_enabled' => true,
                'average_safety_score' => $averageSafetyScore,
                'scored_drivers' => $scoredDrivers,
                'total_drivers' => $driversCount,
                'min_score' => $minScore,
                'max_score' => $maxScore,
                'total_observed' => $totalObserved,
                'total_excluded' => $totalExcluded,
                'harsh_braking_count' => $harshBrakingCount,
                'rapid_acceleration_count' => $rapidAccelerationCount,
                'pending_appeals' => $pendingAppeals,
                'approved_appeals' => $approvedAppeals,
                'rejected_appeals' => $rejectedAppeals,
                'daily_trend' => array_values($trendBuckets),
                'driver_scores' => $driverScores,
                'recent_harsh_events' => $recentEvents,
            ];
        }

        // 5. alerts_summary
        $alertRows = DB::table('alert_occurrences')
            ->where('organization_id', $organization)
            ->where('state', '<>', 'superseded')
            ->where(function ($q) use ($cut) {
                $q->where('state', 'open')->orWhere('occurred_at', '>=', $cut);
            })
            ->get(['state', 'severity', 'kind']);

        $openAlerts = 0;
        $ackAlerts = 0;
        $resAlerts = 0;
        $bySeverity = [];
        $byKind = [];

        foreach ($alertRows as $a) {
            if ($a->state === 'open') {
                $openAlerts++;
            } elseif ($a->state === 'acknowledged') {
                $ackAlerts++;
            } elseif ($a->state === 'resolved') {
                $resAlerts++;
            }
            $sev = $a->severity ?: 'unknown';
            $bySeverity[$sev] = ($bySeverity[$sev] ?? 0) + 1;
            $k = $a->kind ?: 'unknown';
            $byKind[$k] = ($byKind[$k] ?? 0) + 1;
        }

        $alertsSummary = [
            'open' => $openAlerts,
            'acknowledged' => $ackAlerts,
            'resolved' => $resAlerts,
            'total' => count($alertRows),
            'by_severity' => $bySeverity,
            'by_kind' => $byKind,
        ];

        // 6. compliance_summary
        $todayDate = $today->toDateString();
        $complianceSummary = [
            'total_obligations' => $complianceObligationsCount,
            'verified_records' => DB::table('compliance_records')->where('organization_id', $organization)->where('state', 'verified')->count(),
            'pending_records' => DB::table('compliance_records')->where('organization_id', $organization)->where('state', 'pending')->count(),
            'expired_or_overdue' => DB::table('compliance_records')->where('organization_id', $organization)->where('expires_on', '<', $todayDate)->count(),
        ];

        // 7. maintenance_summary
        $maintenanceSummary = [
            'assigned_plans' => DB::table('vehicle_maintenance_plans')->where('organization_id', $organization)->where('status', 'active')->count(),
            'total_records' => DB::table('maintenance_service_records')->where('organization_id', $organization)->count(),
            'completed_last_30d' => DB::table('maintenance_service_records')->where('organization_id', $organization)->where('performed_at', '>=', $cut)->count(),
        ];

        // 8. daily_telemetry
        foreach ($telemetryBuckets as $dateStr => &$bucket) {
            $dayStart = CarbonImmutable::parse($dateStr, $tz)->startOfDay()->setTimezone('UTC');
            $dayEnd = CarbonImmutable::parse($dateStr, $tz)->addDay()->startOfDay()->setTimezone('UTC');
            $bucket['samples'] = DB::table('telemetry_samples')
                ->where('organization_id', $organization)
                ->where('occurred_at', '>=', $dayStart)
                ->where('occurred_at', '<', $dayEnd)
                ->count();
        }
        unset($bucket);

        $dailyDistances = DB::table('vehicle_distance_segments')
            ->where('organization_id', $organization)
            ->whereNull('superseded_at')
            ->where('from_at', '>=', $windowStartUtc)
            ->whereNotNull('distance_m')
            ->select('from_at', 'distance_m')
            ->get();

        foreach ($dailyDistances as $row) {
            $d = CarbonImmutable::parse($row->from_at)->setTimezone($tz)->toDateString();
            if (isset($telemetryBuckets[$d])) {
                $telemetryBuckets[$d]['distance_km'] += ((float) $row->distance_m) / 1000.0;
            }
        }

        $dailyEnergies = DB::table('vehicle_energy_segments')
            ->where('organization_id', $organization)
            ->whereNull('superseded_at')
            ->where('from_at', '>=', $windowStartUtc)
            ->whereNotNull('litres')
            ->select('from_at', 'litres')
            ->get();

        foreach ($dailyEnergies as $row) {
            $d = CarbonImmutable::parse($row->from_at)->setTimezone($tz)->toDateString();
            if (isset($telemetryBuckets[$d])) {
                $telemetryBuckets[$d]['fuel_litres'] += (float) $row->litres;
            }
        }

        foreach ($telemetryBuckets as &$bucket) {
            $bucket['distance_km'] = round($bucket['distance_km'], 2);
            $bucket['fuel_litres'] = round($bucket['fuel_litres'], 2);
        }
        unset($bucket);

        return ApiResponse::success($r, [
            'as_of' => now()->toIso8601String(),
            'vehicles' => $vehiclesCount,
            'drivers' => $driversCount,
            'safety_observations' => $safetyObsCount,
            'open_alerts' => $openAlertsCount,
            'compliance_obligations' => $complianceObligationsCount,
            'fleet_summary' => $fleetSummary,
            'safety_summary' => $safetySummary,
            'alerts_summary' => $alertsSummary,
            'compliance_summary' => $complianceSummary,
            'maintenance_summary' => $maintenanceSummary,
            'daily_telemetry' => array_values($telemetryBuckets),
        ], 'Organization Report Retrieved');
    }
    public function configuration(Request $r, string $organization, string $vehicle)
    {
        $this->allow($r,'device_config.publish'); app(Entitlements::class)->requireActive($organization);
        $settings=json_decode(DB::table('organization_settings')->where('organization_id',$organization)->value('settings') ?? '{}',true);
        $plan=app(Entitlements::class)->plan($organization);
        $settings['telemetry_interval_seconds']=max($settings['telemetry_interval_seconds'] ?? 2,$plan['telemetry_min_seconds'] ?? 2);
        $settings['cellular_enabled']=in_array($plan['cellular_enabled'] ?? false,[true,1],true); $settings['safety_enabled']=in_array($plan['safety_enabled'] ?? false,[true,1],true);
        return DB::transaction(function () use ($r,$organization,$vehicle,$settings) {
            $id=app(DeviceCommands::class)->issue($organization,$vehicle,'configuration',$settings,$r->user()->id);
            $audit=app(\App\Services\ActionAudit::class); $audit->outbox($audit->event('device.configuration.queue','succeeded','http',$audit->context($r)+['command_id'=>$id]));
            return ApiResponse::success($r,['command_id'=>$id],'Device Configuration Queued','Configuration is not applied until acknowledged by the Pi.',202);
        });
    }
    private function photoDriver(Request $r, string $org, string $driver, bool $write): object
    {
        $d=DB::table('drivers')->where('organization_id',$org)->where('id',$driver)->first() ?? abort(404);
        $p=app(Permissions::class);
        abort_unless($p->allows($r->attributes->get('membership'),$write ? 'drivers.write' : 'drivers.read') || (!$write && $d->status === 'active' && $d->user_id === $r->user()->id && $p->allows($r->attributes->get('membership'),'profile.own')),403);
        return $d;
    }
    public function uploadPhoto(Request $r, string $organization, string $driver)
    {
        $this->photoDriver($r,$organization,$driver,true);
        $v=$r->validate(['version'=>'required|integer|min:1','content_base64'=>'required|string|max:2800000']);
        $assets=app(\App\Services\PrivateAssets::class);
        $row=$assets->upload($r,$organization,$v+['target_type'=>'driver','target_id'=>$driver,'purpose'=>'photo','filename'=>'driver-photo']);
        return ApiResponse::success($r,['version'=>$v['version']+1,'item'=>$assets->metadata($row)],'Driver Photo Saved');
    }
    public function photo(Request $r, string $organization, string $driver)
    {
        $d=$this->photoDriver($r,$organization,$driver,false);
        $assets=app(\App\Services\PrivateAssets::class);
        if ($d->photo_asset_id) {
            $row=$assets->row($r,$organization,$d->photo_asset_id);
            return ApiResponse::success($r,['item'=>$assets->metadata($row),'link'=>$assets->link($r,$row)],'Private Driver Photo Retrieved');
        }
        // Legacy photos are preserved, never silently migrated/deleted. Explicit reupload moves to S3.
        abort_unless($d->photo_path && Storage::disk('local')->exists($d->photo_path),404);
        return ApiResponse::success($r,['legacy_local_photo'=>true,'reupload_required'=>true],'Legacy Photo Requires Reupload','The original private local file is preserved. An authorized staff member must explicitly reupload it through validation to enable direct signed S3 viewing.');
    }
}
