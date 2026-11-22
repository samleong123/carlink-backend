<?php

namespace App\Services;

use App\Services\Maintenance\MaintenanceStatus;
use App\Support\EmissionFactors;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FleetCarbonAi
{
    public function __construct(
        private readonly GeminiJsonClient $gemini,
        private readonly Entitlements $entitlements,
        private readonly MaintenanceStatus $maintenanceStatus,
    ) {
    }

    /**
     * Generate a transient organization-scoped fleet fuel & carbon advisory analysis.
     */
    public function analyze(string $organizationId, int $days = 30): array
    {
        $days = max(1, min(90, $days));
        $now = CarbonImmutable::now('UTC');
        $from = $now->subDays($days);
        if (config('saas.enforce_subscriptions')) {
            $from = $from->max($this->entitlements->cutoff($organizationId));
        }

        $rawSettings = DB::table('organization_settings')
            ->where('organization_id', $organizationId)
            ->value('settings');
        $orgSettings = $rawSettings ? (json_decode($rawSettings, true) ?: []) : [];

        $vehicles = DB::table('vehicles')
            ->where('organization_id', $organizationId)
            ->where('status', '<>', 'archived')
            ->orderBy('registration_number')
            ->get(['id', 'registration_number', 'make', 'model', 'fuel_type', 'status']);

        $fuelByVehicle = DB::table('vehicle_energy_segments')
            ->where('organization_id', $organizationId)
            ->whereNull('superseded_at')
            ->where('from_at', '>=', $from)
            ->where('from_at', '<=', $now)
            ->whereNotNull('litres')
            ->select('vehicle_id', DB::raw('SUM(litres) as total_litres'))
            ->groupBy('vehicle_id')
            ->pluck('total_litres', 'vehicle_id');

        $distanceByVehicle = DB::table('vehicle_distance_segments')
            ->where('organization_id', $organizationId)
            ->whereNull('superseded_at')
            ->where('from_at', '>=', $from)
            ->where('from_at', '<=', $now)
            ->whereNotNull('distance_m')
            ->select('vehicle_id', DB::raw('SUM(distance_m) as total_distance_m'))
            ->groupBy('vehicle_id')
            ->pluck('total_distance_m', 'vehicle_id');

        $journeysByVehicle = DB::table('journeys')
            ->where('organization_id', $organizationId)
            ->where('started_at', '>=', $from)
            ->where('started_at', '<=', $now)
            ->select('vehicle_id', DB::raw('COUNT(*) as journeys_count'))
            ->groupBy('vehicle_id')
            ->pluck('journeys_count', 'vehicle_id');

        $idleSecondsByVehicle = $this->computeIdleSecondsByVehicle($organizationId, $from, $now);
        [$maintenanceFlagsByVehicle, $dueMaintenanceItems, $overdueMaintenanceCount] = $this->collectMaintenanceFlags($organizationId, $now);

        $totalDistanceKm = 0.0;
        $totalFuelLitres = 0.0;
        $totalCo2Kg = 0.0;
        $totalIdleMinutes = 0.0;
        $vehicleMetrics = [];

        foreach ($vehicles as $vehicle) {
            $distanceKm = ((float) ($distanceByVehicle[$vehicle->id] ?? 0.0)) / 1000.0;
            $fuelLitres = (float) ($fuelByVehicle[$vehicle->id] ?? 0.0);
            $factor = EmissionFactors::resolve($organizationId, $vehicle->fuel_type, $orgSettings);
            $co2Kg = $factor['kg_per_litre'] !== null ? $fuelLitres * (float) $factor['kg_per_litre'] : 0.0;
            $idleMinutes = ((float) ($idleSecondsByVehicle[$vehicle->id] ?? 0.0)) / 60.0;

            $totalDistanceKm += $distanceKm;
            $totalFuelLitres += $fuelLitres;
            $totalCo2Kg += $co2Kg;
            $totalIdleMinutes += $idleMinutes;

            $vehicleMetrics[] = [
                'vehicle_label' => $this->vehicleLabel($vehicle),
                'fuel_type' => $vehicle->fuel_type ?: 'unknown',
                'status' => $vehicle->status,
                'distance_km' => round($distanceKm, 2),
                'fuel_litres' => round($fuelLitres, 2),
                'co2_kg' => round($co2Kg, 2),
                'litres_per_100km' => $distanceKm > 0.0 ? round(($fuelLitres * 100.0) / $distanceKm, 2) : null,
                'idle_minutes' => round($idleMinutes, 2),
                'journeys_count' => (int) ($journeysByVehicle[$vehicle->id] ?? 0),
                'maintenance_flags' => $maintenanceFlagsByVehicle[$vehicle->id] ?? [],
            ];
        }

        usort($vehicleMetrics, static function (array $a, array $b): int {
            return $b['co2_kg'] <=> $a['co2_kg']
                ?: $b['fuel_litres'] <=> $a['fuel_litres']
                ?: $b['idle_minutes'] <=> $a['idle_minutes']
                ?: $b['distance_km'] <=> $a['distance_km'];
        });
        $topVehicles = array_slice($vehicleMetrics, 0, 10);

        $metricsSummary = [
            'total_vehicles' => $vehicles->count(),
            'total_distance_km' => round($totalDistanceKm, 2),
            'total_fuel_litres' => round($totalFuelLitres, 2),
            'total_co2_kg' => round($totalCo2Kg, 2),
            'total_idle_minutes' => round($totalIdleMinutes, 2),
            'overdue_maintenance_count' => $overdueMaintenanceCount,
        ];

        $promptContext = [
            'period_days' => $days,
            'metrics_summary' => $metricsSummary,
            'top_vehicles' => $topVehicles,
            'maintenance_alerts' => array_slice($dueMaintenanceItems, 0, 15),
        ];

        $prompt = implode("\n", [
            'You are a fleet sustainability, fuel efficiency, and maintenance advisor.',
            'Analyze the following anonymized, organization-scoped fleet fuel, CO2, distance, idle time, and maintenance metrics.',
            'If metrics are zero or datasets are empty, accurately note the lack of observed activity and return practical baseline recommendations with zero waste.',
            'Return ONLY a valid JSON object (no markdown fences or commentary outside JSON) with this exact structure:',
            '{"executive_summary":"string","estimated_avoidable_waste":{"fuel_litres":0.0,"co2_kg":0.0,"rationale":"string"},"outliers":[{"vehicle_label":"string","issue":"string","fuel_litres":0.0,"co2_kg":0.0,"recommended_action":"string"}],"recommendations":[{"priority":"high|medium|low","title":"string","detail":"string","expected_impact":"string"}],"esg_highlights":["string"]}',
            'Fleet metrics JSON:',
            json_encode($promptContext, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $ai = $this->gemini->generateJson($prompt, 'Fleet Carbon AI', 2048);
        $decoded = $ai['data'];

        if (!is_string($decoded['executive_summary'] ?? null) || trim($decoded['executive_summary']) === '') {
            abort(502, 'Fleet Carbon AI provider returned malformed analysis.');
        }

        $wasteRaw = is_array($decoded['estimated_avoidable_waste'] ?? null) ? $decoded['estimated_avoidable_waste'] : [];
        $estimatedWaste = [
            'fuel_litres' => max(0.0, round((float) ($wasteRaw['fuel_litres'] ?? 0.0), 2)),
            'co2_kg' => max(0.0, round((float) ($wasteRaw['co2_kg'] ?? 0.0), 2)),
            'rationale' => trim((string) ($wasteRaw['rationale'] ?? '')),
        ];

        $outliers = [];
        foreach (is_array($decoded['outliers'] ?? null) ? $decoded['outliers'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['vehicle_label'] ?? ''));
            $issue = trim((string) ($item['issue'] ?? ''));
            if ($label === '' || $issue === '') {
                continue;
            }
            $outliers[] = [
                'vehicle_label' => $label,
                'issue' => $issue,
                'fuel_litres' => max(0.0, round((float) ($item['fuel_litres'] ?? 0.0), 2)),
                'co2_kg' => max(0.0, round((float) ($item['co2_kg'] ?? 0.0), 2)),
                'recommended_action' => trim((string) ($item['recommended_action'] ?? '')),
            ];
        }

        $recommendations = [];
        foreach (is_array($decoded['recommendations'] ?? null) ? $decoded['recommendations'] : [] as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            $title = trim((string) ($rec['title'] ?? ''));
            $detail = trim((string) ($rec['detail'] ?? ''));
            if ($title === '' || $detail === '') {
                continue;
            }
            $priority = strtolower(trim((string) ($rec['priority'] ?? 'medium')));
            if (!in_array($priority, ['high', 'medium', 'low'], true)) {
                $priority = 'medium';
            }
            $recommendations[] = [
                'priority' => $priority,
                'title' => $title,
                'detail' => $detail,
                'expected_impact' => trim((string) ($rec['expected_impact'] ?? '')),
            ];
        }

        $esgHighlights = [];
        foreach (is_array($decoded['esg_highlights'] ?? null) ? $decoded['esg_highlights'] : [] as $highlight) {
            if (is_string($highlight) && trim($highlight) !== '') {
                $esgHighlights[] = trim($highlight);
            }
        }

        return [
            'model' => $ai['model'],
            'period_days' => $days,
            'advisory_only' => true,
            'metrics_summary' => $metricsSummary,
            'executive_summary' => trim($decoded['executive_summary']),
            'estimated_avoidable_waste' => $estimatedWaste,
            'outliers' => $outliers,
            'recommendations' => $recommendations,
            'esg_highlights' => $esgHighlights,
        ];
    }

    /**
     * @return array<string,float>
     */
    private function computeIdleSecondsByVehicle(string $organizationId, CarbonImmutable $from, CarbonImmutable $now): array
    {
        $maxGap = $this->entitlements->maxCompatibleGapSeconds($organizationId);
        $idleSeconds = [];
        $previous = null;

        $samples = DB::table('telemetry_samples')
            ->where('organization_id', $organizationId)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<=', $now)
            ->orderBy('vehicle_id')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->select(['vehicle_id', 'installation_id', 'source_format', 'occurred_at', 'speed_kmh', 'engine_rpm', 'fuel_rate_lph', 'vehicle_data'])
            ->cursor();

        foreach ($samples as $sample) {
            if (
                $previous !== null
                && $previous->vehicle_id === $sample->vehicle_id
                && $previous->installation_id === $sample->installation_id
                && $previous->source_format === $sample->source_format
            ) {
                $dt = CarbonImmutable::parse($previous->occurred_at)->diffInSeconds(CarbonImmutable::parse($sample->occurred_at), false);
                if ($dt > 0 && $dt <= $maxGap && $this->isIdlingInterval($previous, $sample)) {
                    $idleSeconds[$sample->vehicle_id] = ($idleSeconds[$sample->vehicle_id] ?? 0.0) + $dt;
                }
            }
            $previous = $sample;
        }

        return $idleSeconds;
    }

    private function isIdlingInterval(object $a, object $b): bool
    {
        if ($a->speed_kmh === null || $b->speed_kmh === null) {
            return false;
        }
        if ((float) $a->speed_kmh >= 2.0 || (float) $b->speed_kmh >= 2.0) {
            return false;
        }

        return $this->isEngineRunning($a) || $this->isEngineRunning($b);
    }

    private function isEngineRunning(object $sample): bool
    {
        if ($sample->engine_rpm !== null && (float) $sample->engine_rpm > 0.0) {
            return true;
        }
        if ($sample->fuel_rate_lph !== null && (float) $sample->fuel_rate_lph > 0.0) {
            return true;
        }
        if (is_string($sample->vehicle_data) && $sample->vehicle_data !== '') {
            $vehicleData = json_decode($sample->vehicle_data, true);
            if (is_array($vehicleData) && ($vehicleData['engine_running'] ?? null) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0:array<string,list<string>>,1:list<array<string,mixed>>,2:int}
     */
    private function collectMaintenanceFlags(string $organizationId, CarbonImmutable $now): array
    {
        $rows = DB::table('vehicle_maintenance_plans as a')
            ->join('vehicles as v', fn ($j) => $j->on('v.id', '=', 'a.vehicle_id')->on('v.organization_id', '=', 'a.organization_id'))
            ->join('maintenance_plans as p', 'p.id', '=', 'a.plan_id')
            ->join('maintenance_service_cycles as c', 'c.assignment_id', '=', 'a.id')
            ->where('a.organization_id', $organizationId)
            ->where('a.status', 'active')
            ->where('v.status', '<>', 'archived')
            ->whereNull('c.ended_at')
            ->get([
                'a.vehicle_id',
                'v.registration_number',
                'v.make',
                'v.model',
                'p.name as plan_name',
                'p.service_type',
                'c.serviced_at',
                'c.interval_snapshot',
                'c.baseline_quality',
                'c.carry_in_distance_m',
                'c.carry_in_as_of',
            ]);

        $flagsByVehicle = [];
        $items = [];
        $overdueCount = 0;

        foreach ($rows as $row) {
            $eval = $this->maintenanceStatus->cycle($organizationId, $row->vehicle_id, $row, $now);
            $isOverdue = ($eval['remaining_km'] !== null && $eval['remaining_km'] <= 0)
                || ($eval['remaining_days'] !== null && $eval['remaining_days'] <= 0);
            $isDueSoon = !$isOverdue && (
                ($eval['remaining_km'] !== null && $eval['remaining_km'] <= 1000)
                || ($eval['remaining_days'] !== null && $eval['remaining_days'] <= 14)
            );

            if (!$isOverdue && !$isDueSoon) {
                continue;
            }

            if ($isOverdue) {
                $overdueCount++;
            }

            $status = $isOverdue ? 'overdue' : 'due_soon';
            $planLabel = $this->gemini->scrubText($row->plan_name, 80);
            $serviceType = $this->gemini->scrubText($row->service_type, 60);
            $flagsByVehicle[$row->vehicle_id][] = "{$status}: {$planLabel}";

            $items[] = [
                'vehicle_label' => $this->vehicleLabel($row),
                'status' => $status,
                'plan_name' => $planLabel,
                'service_type' => $serviceType,
                'remaining_km' => $eval['remaining_km'] !== null ? round((float) $eval['remaining_km'], 1) : null,
                'remaining_days' => $eval['remaining_days'],
            ];
        }

        return [$flagsByVehicle, $items, $overdueCount];
    }

    private function vehicleLabel(object $vehicle): string
    {
        $makeModel = trim(($vehicle->make ?? '') . ' ' . ($vehicle->model ?? ''));
        $label = trim(implode(' ', array_filter([
            $vehicle->registration_number ?? null,
            $makeModel !== '' ? "({$makeModel})" : null,
        ])));

        return $this->gemini->scrubText($label !== '' ? $label : 'Vehicle', 100);
    }
}
