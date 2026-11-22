<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JourneyController
{
    private function authorize(Request $request): void
    {
        // Driver segment-level privacy is not yet implemented; do not expose a
        // shared journey's other drivers or its vehicle history to drivers.
        abort_unless(app(\App\Services\Permissions::class)->allows($request->attributes->get('membership'), 'journeys.read'), 403);
    }

    public function index(Request $request, string $organization)
    {
        $this->authorize($request);
        $filter = $request->validate(['vehicle_id' => 'nullable|uuid', 'driver_id' => 'prohibited', 'from' => 'nullable|date',
            'to' => 'nullable|date|after:from', 'state' => 'nullable|in:active,completed,incomplete', 'limit' => 'nullable|integer|min:1|max:100']);
        $query = DB::table('journeys')->where('organization_id', $organization);
        if (!empty($filter['vehicle_id'])) $query->where('vehicle_id', $filter['vehicle_id']);
        if (!empty($filter['from'])) $query->where('started_at', '>=', CarbonImmutable::parse($filter['from'])->utc());
        if (!empty($filter['to'])) $query->where('started_at', '<', CarbonImmutable::parse($filter['to'])->utc());
        if (!empty($filter['state'])) $query->where('state', $filter['state']);
        $items = $query->orderByDesc('started_at')->orderByDesc('id')->limit($filter['limit'] ?? 100)->get()->map(fn ($item) => $this->represent($item));
        return ApiResponse::success($request, ['items' => $items, 'driver_attribution' => 'not_implemented'], 'Journeys Retrieved');
    }

    public function show(Request $request, string $organization, string $journey)
    {
        $this->authorize($request);
        $item = DB::table('journeys')->where('organization_id', $organization)->where('id', $journey)->first() ?? abort(404);
        return ApiResponse::success($request, ['journey' => $this->represent($item)], 'Journey Retrieved');
    }

    private function represent(object $item): array
    {
        $endReason = $item->end_reason === 'telemetry_gap' ? 'automatic_end' : $item->end_reason;
        $from = CarbonImmutable::parse($item->started_at)->utc();
        $to = CarbonImmutable::parse($item->ended_at ?? $item->last_observed_at ?? $item->started_at)->utc();

        $win = app(\App\Services\Mileage\DistanceWindow::class)->summarize($item->organization_id, $item->vehicle_id, $from, $to);
        $winDistance = (float) ($win['distance_m'] ?? 0.0);

        $samples = DB::table('telemetry_samples')
            ->where('organization_id', $item->organization_id)
            ->where('vehicle_id', $item->vehicle_id)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<=', $to)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'occurred_at', 'speed_kmh', 'latitude', 'longitude', 'fuel_rate_lph', 'vehicle_data']);

        $sampleSpeedMetres = 0.0;
        $gpsMetres = 0.0;
        $fuelLitres = 0.0;

        $prev = null;
        $prevTime = null;
        $prevFuelRate = null;
        $prevGps = null;

        foreach ($samples as $s) {
            $currTime = CarbonImmutable::parse($s->occurred_at)->utc();
            $currFuelRate = $this->extractFuelRateLph($s);

            if ($prev !== null) {
                $dt = $this->elapsedSeconds($prevTime, $currTime);
                if ($dt > 0 && $dt <= 3600) {
                    if ($prev->speed_kmh !== null && $s->speed_kmh !== null) {
                        $sampleSpeedMetres += (((float) $s->speed_kmh + (float) $prev->speed_kmh) * $dt) / 7.2;
                    }
                    if ($prevFuelRate !== null && $currFuelRate !== null) {
                        $fuelLitres += (($prevFuelRate + $currFuelRate) * $dt) / 7200.0;
                    }
                }
            }

            if ($s->latitude !== null && $s->longitude !== null) {
                if ($prevGps !== null) {
                    $gpsMetres += $this->haversineMetres(
                        (float) $prevGps->latitude,
                        (float) $prevGps->longitude,
                        (float) $s->latitude,
                        (float) $s->longitude,
                    );
                }
                $prevGps = $s;
            }

            $prev = $s;
            $prevTime = $currTime;
            $prevFuelRate = $currFuelRate;
        }

        $obsSpeedMetres = 0.0;
        if ($winDistance <= 0 && $sampleSpeedMetres <= 0 && $gpsMetres <= 0) {
            $observations = DB::table('mileage_speed_observations')
                ->where('organization_id', $item->organization_id)
                ->where('vehicle_id', $item->vehicle_id)
                ->where('observed_at', '>=', $from)
                ->where('observed_at', '<=', $to)
                ->orderBy('observed_at')
                ->orderBy('id')
                ->get(['observed_at', 'speed_kmh']);

            $prevObs = null;
            $prevObsTime = null;
            foreach ($observations as $obs) {
                $obsTime = CarbonImmutable::parse($obs->observed_at)->utc();
                if ($prevObs !== null) {
                    $dt = $this->elapsedSeconds($prevObsTime, $obsTime);
                    if ($dt > 0 && $dt <= 3600 && $prevObs->speed_kmh !== null && $obs->speed_kmh !== null) {
                        $obsSpeedMetres += (((float) $obs->speed_kmh + (float) $prevObs->speed_kmh) * $dt) / 7.2;
                    }
                }
                $prevObs = $obs;
                $prevObsTime = $obsTime;
            }
        }

        if ($winDistance > 0) {
            $distanceM = $winDistance;
            $distanceQuality = 'speed_trapezoid';
        } elseif ($sampleSpeedMetres > 0) {
            $distanceM = $sampleSpeedMetres;
            $distanceQuality = 'speed_estimated';
        } elseif ($gpsMetres > 0) {
            $distanceM = $gpsMetres;
            $distanceQuality = 'gps_coordinates';
        } elseif ($obsSpeedMetres > 0) {
            $distanceM = $obsSpeedMetres;
            $distanceQuality = 'speed_trapezoid';
        } else {
            $distanceM = 0.0;
            $distanceQuality = 'estimated';
        }

        if ($fuelLitres <= 0) {
            $fuelLitres = $this->energySegmentLitres($item->organization_id, $item->vehicle_id, $from, $to);
        }

        $lPer100km = null;
        $kmPerL = null;
        $fuelEfficiency = null;
        if ($distanceM > 0 && $fuelLitres > 0) {
            $distanceKm = $distanceM / 1000.0;
            $lPer100km = round(($fuelLitres / $distanceKm) * 100.0, 2);
            $kmPerL = round($distanceKm / $fuelLitres, 2);
            $fuelEfficiency = $lPer100km . ' L/100km / ' . $kmPerL . ' km/L';
        }

        return [
            'id' => $item->id,
            'vehicle_id' => $item->vehicle_id,
            'state' => $item->state,
            'started_at' => $item->started_at,
            'ended_at' => $item->ended_at,
            'last_observed_at' => $item->last_observed_at,
            'end_reason' => $endReason,
            'unknown_seconds' => (int) $item->unknown_seconds,
            'detection_version' => $item->detection_version,
            'revision' => (int) $item->revision,
            'distance_m' => round($distanceM, 2),
            'distance_km' => round($distanceM / 1000.0, 3),
            'distance_quality' => $distanceQuality,
            'fuel_litres' => $fuelLitres > 0 ? round($fuelLitres, 4) : null,
            'fuel_efficiency_l_per_100km' => $lPer100km,
            'fuel_efficiency_km_per_l' => $kmPerL,
            'fuel_efficiency' => $fuelEfficiency,
            'driver_attribution' => 'not_implemented',
            'is_passenger_trip' => false,
        ];
    }

    /** Compute elapsed seconds between two UTC timestamps with sub-second precision. */
    private function elapsedSeconds(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) $to->format('U.u') - (float) $from->format('U.u');
    }

    /** Compute great-circle Haversine distance in metres between two coordinates. */
    private function haversineMetres(float $lat1Deg, float $lon1Deg, float $lat2Deg, float $lon2Deg): float
    {
        $lat1 = deg2rad($lat1Deg);
        $lon1 = deg2rad($lon1Deg);
        $lat2 = deg2rad($lat2Deg);
        $lon2 = deg2rad($lon2Deg);
        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;
        $a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return 6371000 * $c;
    }

    /** Extract instantaneous fuel rate (L/h) from sample column or vehicle_data JSON. */
    private function extractFuelRateLph(object $sample): ?float
    {
        if ($sample->fuel_rate_lph !== null) {
            return (float) $sample->fuel_rate_lph;
        }

        if (empty($sample->vehicle_data)) {
            return null;
        }

        $data = is_string($sample->vehicle_data)
            ? json_decode($sample->vehicle_data, true)
            : (is_array($sample->vehicle_data) ? $sample->vehicle_data : null);

        if (!is_array($data)) {
            return null;
        }

        if (isset($data['fuel_rate_lph']) && is_numeric($data['fuel_rate_lph'])) {
            return (float) $data['fuel_rate_lph'];
        }

        if (isset($data['maf_gps']) && is_numeric($data['maf_gps']) && (float) $data['maf_gps'] > 0) {
            return ((float) $data['maf_gps'] * 3600.0) / (14.7 * 745.0);
        }

        return null;
    }

    /** Fallback fuel integration from pre-computed vehicle_energy_segments overlapping the journey window. */
    private function energySegmentLitres(string $organizationId, string $vehicleId, CarbonImmutable $from, CarbonImmutable $to): float
    {
        $segments = DB::table('vehicle_energy_segments as e')
            ->join('telemetry_samples as a', 'a.id', '=', 'e.start_sample_id')
            ->join('telemetry_samples as b', 'b.id', '=', 'e.end_sample_id')
            ->where('e.organization_id', $organizationId)
            ->where('e.vehicle_id', $vehicleId)
            ->whereNull('e.superseded_at')
            ->where('e.to_at', '>', $from)
            ->where('e.from_at', '<', $to)
            ->select('e.from_at', 'e.to_at', 'e.litres', 'a.fuel_rate_lph as rate_a', 'b.fuel_rate_lph as rate_b')
            ->cursor();

        $litres = 0.0;
        foreach ($segments as $s) {
            if ($s->litres === null) {
                continue;
            }
            $a = CarbonImmutable::parse($s->from_at)->utc();
            $b = CarbonImmutable::parse($s->to_at)->utc();
            $duration = $this->elapsedSeconds($a, $b);
            $left = $a->max($from);
            $right = $b->min($to);
            $seconds = $this->elapsedSeconds($left, $right);
            if ($duration <= 0 || $seconds <= 0) {
                continue;
            }
            $slope = ((float) $s->rate_b - (float) $s->rate_a) / $duration;
            $rateLeft = (float) $s->rate_a + $slope * $this->elapsedSeconds($a, $left);
            $rateRight = (float) $s->rate_a + $slope * $this->elapsedSeconds($a, $right);
            $litres += ($rateLeft + $rateRight) * $seconds / 7200.0;
        }

        return $litres;
    }
}
