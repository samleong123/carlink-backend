<?php

namespace App\Services;

use App\Http\Controllers\Api\V1\SafetyController;
use App\Support\EmissionFactors;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DriverEcoCoachAi
{
    private const ALLOWED_CATEGORIES = ['idle', 'braking', 'acceleration', 'speed', 'smoothness', 'general'];

    public function __construct(
        private readonly GeminiJsonClient $gemini,
        private readonly Entitlements $entitlements,
        private readonly SafetyController $safetyController,
    ) {
    }

    /**
     * Generate a transient, strictly single-driver eco-driving coaching brief.
     * Never queries other drivers or sends driver PII externally.
     */
    public function coach(string $organizationId, string $driverId, int $days = 14): array
    {
        $days = max(1, min(90, $days));
        $now = CarbonImmutable::now('UTC');
        $from = $now->subDays($days);
        if (config('saas.enforce_subscriptions')) {
            $from = $from->max($this->entitlements->cutoff($organizationId));
        }

        $maxGap = $this->entitlements->maxCompatibleGapSeconds($organizationId);
        $rawSettings = DB::table('organization_settings')
            ->where('organization_id', $organizationId)
            ->value('settings');
        $orgSettings = $rawSettings ? (json_decode($rawSettings, true) ?: []) : [];
        $vehicleFuelTypes = DB::table('vehicles')
            ->where('organization_id', $organizationId)
            ->pluck('fuel_type', 'id')
            ->all();

        $shiftIdsQuery = DB::table('driver_shifts')
            ->where('organization_id', $organizationId)
            ->where('driver_id', $driverId)
            ->select('id');

        $shiftsCount = DB::table('driver_shifts')
            ->where('organization_id', $organizationId)
            ->where('driver_id', $driverId)
            ->where('started_at', '<=', $now)
            ->whereRaw('COALESCE(ended_at, attribution_expires_at) >= ?', [$from])
            ->count();

        $metres = 0.0;
        $fuel = 0.0;
        $co2Kg = 0.0;
        $alignedMetres = 0.0;
        $alignedFuel = 0.0;
        $alignedSeconds = 0;
        $idleSeconds = 0;
        $previous = null;

        $samples = DB::table('telemetry_samples')
            ->where('organization_id', $organizationId)
            ->whereIn('authorized_shift_id', $shiftIdsQuery)
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<=', $now)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->select([
                'id',
                'vehicle_id',
                'installation_id',
                'authorized_shift_id',
                'source_format',
                'occurred_at',
                'speed_kmh',
                'engine_rpm',
                'fuel_rate_lph',
                'fuel_rate_source',
                'vehicle_data',
            ])
            ->cursor();

        foreach ($samples as $s) {
            if (
                $previous !== null
                && $s->authorized_shift_id === $previous->authorized_shift_id
                && $s->installation_id === $previous->installation_id
                && $s->source_format === $previous->source_format
            ) {
                $dt = CarbonImmutable::parse($previous->occurred_at)->diffInSeconds(CarbonImmutable::parse($s->occurred_at), false);
                if ($dt > 0 && $dt <= $maxGap) {
                    if ($s->speed_kmh !== null && $previous->speed_kmh !== null) {
                        $metres += ((float) $s->speed_kmh + (float) $previous->speed_kmh) * $dt / 7.2;
                        if ((float) $s->speed_kmh < 2.0 && (float) $previous->speed_kmh < 2.0 && ($this->isEngineRunning($previous) || $this->isEngineRunning($s))) {
                            $idleSeconds += $dt;
                        }
                    }

                    if ($s->fuel_rate_lph !== null && $previous->fuel_rate_lph !== null && $s->fuel_rate_source === $previous->fuel_rate_source) {
                        $intervalFuel = ((float) $s->fuel_rate_lph + (float) $previous->fuel_rate_lph) * $dt / 7200;
                        $fuel += $intervalFuel;
                        $factor = EmissionFactors::resolve($organizationId, $vehicleFuelTypes[$s->vehicle_id] ?? 'petrol', $orgSettings);
                        if ($factor['kg_per_litre'] !== null) {
                            $co2Kg += $intervalFuel * (float) $factor['kg_per_litre'];
                        }
                    }

                    if (
                        $s->speed_kmh !== null && $previous->speed_kmh !== null
                        && $s->fuel_rate_lph !== null && $previous->fuel_rate_lph !== null
                        && $s->fuel_rate_source === $previous->fuel_rate_source
                    ) {
                        $alignedMetres += ((float) $s->speed_kmh + (float) $previous->speed_kmh) * $dt / 7.2;
                        $alignedFuel += ((float) $s->fuel_rate_lph + (float) $previous->fuel_rate_lph) * $dt / 7200;
                        $alignedSeconds += $dt;
                    }
                }
            }
            $previous = $s;
        }

        $journeysCount = (int) DB::table('telemetry_samples as s')
            ->join('driver_shifts as sh', fn ($j) => $j->on('sh.id', '=', 's.authorized_shift_id')
                ->on('sh.organization_id', '=', 's.organization_id')
                ->on('sh.vehicle_id', '=', 's.vehicle_id')
                ->on('sh.device_installation_id', '=', 's.installation_id'))
            ->join('journeys as j', fn ($j) => $j->on('j.organization_id', '=', 's.organization_id')
                ->on('j.vehicle_id', '=', 's.vehicle_id')
                ->on('s.occurred_at', '>=', 'j.started_at')
                ->on('s.occurred_at', '<=', 'j.last_observed_at'))
            ->where('s.organization_id', $organizationId)
            ->where('sh.driver_id', $driverId)
            ->where('s.occurred_at', '>=', $from)
            ->where('s.occurred_at', '<=', $now)
            ->whereColumn('s.occurred_at', '>=', 'sh.started_at')
            ->whereColumn('s.occurred_at', '<=', 'sh.attribution_expires_at')
            ->whereRaw('s.occurred_at <= COALESCE(sh.ended_at, sh.attribution_expires_at)')
            ->distinct()
            ->count('j.id');

        $plan = $this->entitlements->plan($organizationId);
        $safetyEnabled = !config('saas.enforce_subscriptions')
            || in_array($plan['safety_enabled'] ?? false, [true, 1, '1'], true);

        $safetyScore = null;
        $harshEventsCount = 0;
        $eventBreakdown = [];

        if ($safetyEnabled) {
            $scoreData = $this->safetyController->scoreData($organizationId, $driverId);
            $safetyScore = $scoreData['score'] !== null ? (int) $scoreData['score'] : null;

            $eventRows = DB::table('safety_events')
                ->where('organization_id', $organizationId)
                ->where('driver_id', $driverId)
                ->where('occurred_at', '>=', $from)
                ->where('occurred_at', '<=', $now)
                ->where('state', 'observed')
                ->select('kind', DB::raw('COUNT(*) as total'))
                ->groupBy('kind')
                ->get();

            foreach ($eventRows as $row) {
                $count = (int) $row->total;
                $harshEventsCount += $count;
                $eventBreakdown[(string) $row->kind] = $count;
            }
        }

        $distanceKm = round($metres / 1000.0, 2);
        $fuelLitres = round($fuel, 2);
        $co2TotalKg = round($co2Kg, 2);
        $idleMinutes = round($idleSeconds / 60.0, 2);
        $litresPer100km = ($alignedSeconds > 0 && $alignedMetres > 0.0)
            ? round(($alignedFuel * 100000.0) / $alignedMetres, 2)
            : null;

        $efficiencySnapshot = [
            'distance_km' => $distanceKm,
            'fuel_litres' => $fuelLitres,
            'co2_kg' => $co2TotalKg,
            'litres_per_100km' => $litresPer100km,
            'idle_minutes' => $idleMinutes,
            'safety_score' => $safetyScore,
            'shifts_count' => $shiftsCount,
            'harsh_events_count' => $harshEventsCount,
        ];

        $promptContext = [
            'period_days' => $days,
            'efficiency_snapshot' => $efficiencySnapshot,
            'journeys_count' => $journeysCount,
            'safety_feature_enabled' => $safetyEnabled,
            'event_breakdown' => (object) $eventBreakdown,
        ];

        $prompt = implode("\n", [
            'You are a supportive personal eco-driving and fuel efficiency coach for a fleet driver.',
            'Analyze the following anonymized driving efficiency metrics for this single driver only.',
            'If metrics are zero or no trips were recorded, encourage the driver warmly, note that no recent trip data was observed in the window, and provide general baseline eco-driving habits with zero estimated savings.',
            'Return ONLY a valid JSON object (no markdown fences or commentary outside JSON) with this exact structure:',
            '{"encouragement_summary":"string","potential_savings":{"fuel_litres":0.0,"co2_kg":0.0,"note":"string"},"coaching_tips":[{"category":"idle|braking|acceleration|speed|smoothness|general","title":"string","tip":"string","estimated_benefit":"string"}]}',
            'Driver anonymized metrics JSON:',
            json_encode($promptContext, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $ai = $this->gemini->generateJson($prompt, 'Driver Eco-Coach AI', 1536);
        $decoded = $ai['data'];

        if (!is_string($decoded['encouragement_summary'] ?? null) || trim($decoded['encouragement_summary']) === '') {
            abort(502, 'Driver Eco-Coach AI provider returned malformed analysis.');
        }

        $savingsRaw = is_array($decoded['potential_savings'] ?? null) ? $decoded['potential_savings'] : [];
        $potentialSavings = [
            'fuel_litres' => max(0.0, round((float) ($savingsRaw['fuel_litres'] ?? 0.0), 2)),
            'co2_kg' => max(0.0, round((float) ($savingsRaw['co2_kg'] ?? 0.0), 2)),
            'note' => trim((string) ($savingsRaw['note'] ?? '')),
        ];

        $tips = [];
        foreach (is_array($decoded['coaching_tips'] ?? null) ? $decoded['coaching_tips'] : [] as $tip) {
            if (!is_array($tip)) {
                continue;
            }
            $title = trim((string) ($tip['title'] ?? ''));
            $body = trim((string) ($tip['tip'] ?? ''));
            if ($title === '' || $body === '') {
                continue;
            }
            $category = strtolower(trim((string) ($tip['category'] ?? 'general')));
            if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
                $category = 'general';
            }
            $tips[] = [
                'category' => $category,
                'title' => $title,
                'tip' => $body,
                'estimated_benefit' => trim((string) ($tip['estimated_benefit'] ?? '')),
            ];
        }

        return [
            'model' => $ai['model'],
            'period_days' => $days,
            'advisory_only' => true,
            'efficiency_snapshot' => $efficiencySnapshot,
            'encouragement_summary' => trim($decoded['encouragement_summary']),
            'potential_savings' => $potentialSavings,
            'coaching_tips' => $tips,
        ];
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
}
