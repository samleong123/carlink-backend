<?php

namespace App\Services\Telemetry;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Strict known measurements. Unknown extensions cannot influence projections. */
class TelemetryValidator
{
    public const METRICS = [
        'speed_kmh'=>[0,250], 'engine_rpm'=>[0,30000], 'coolant_temp_c'=>[-60,250], 'engine_load_pct'=>[0,100],
        'throttle_pct'=>[0,100], 'intake_temp_c'=>[-60,250], 'maf_gps'=>[0,2000], 'fuel_rate_lph'=>[0,1000],
        'fuel_level_pct'=>[0,100], 'odometer_km'=>[0,1000000000], 'engine_runtime_s'=>[0,100000000000], 'ecu_voltage_v'=>[0,100],
    ];

    public function reason(array $event): ?string
    {
        $clock=$event['clock'];
        if (array_key_exists('synchronized',$clock) && !is_bool($clock['synchronized'])) return 'invalid_clock';
        if (isset($clock['source']) && (!is_string($clock['source']) || strlen($clock['source']) > 100)) return 'invalid_clock';
        if (isset($clock['estimated_error_ms']) && ((!is_int($clock['estimated_error_ms']) && !is_float($clock['estimated_error_ms'])) || $clock['estimated_error_ms'] < 0 || !is_finite((float)$clock['estimated_error_ms']))) return 'invalid_clock';
        if (array_key_exists('vehicle',$event['payload']) && !is_array($event['payload']['vehicle'])) return 'invalid_vehicle';
        $vehicle = $event['payload']['vehicle'] ?? [];
        if (!is_array($vehicle)) return 'invalid_vehicle';
        foreach (array_merge(array_keys(self::METRICS), ['mil_on', 'engine_state']) as $key) {
            if (!array_key_exists($key, $vehicle)) continue;
            $m = $vehicle[$key];
            if (!is_array($m) || !array_key_exists('value', $m) || !in_array($m['quality'] ?? null, ['valid','estimated','unsupported','unavailable','stale','ambiguous','invalid'], true)) return 'invalid_measurement';
            if (in_array($m['quality'], ['unsupported', 'unavailable'], true) && $m['value'] !== null) return 'invalid_missing_value';
            if (in_array($m['quality'], ['valid','estimated'], true) && $m['value'] === null) return 'invalid_measured_value';
            if ($m['value'] !== null) {
                if ($key === 'mil_on' && !is_bool($m['value'])) return 'invalid_boolean';
                if ($key === 'engine_state' && !in_array($m['value'], ['running','off','unknown'], true)) return 'invalid_engine_state';
                if (isset(self::METRICS[$key])) {
                    [$min, $max] = self::METRICS[$key];
                    if ((!is_int($m['value']) && !is_float($m['value'])) || !is_finite((float) $m['value']) || $m['value'] < $min || $m['value'] > $max) return 'invalid_metric_range';
                }
            }
            if ($m['value'] !== null && (!Str::isUuid($m['sample_id'] ?? '') || !is_int($m['observed_uptime_ms'] ?? null) || $m['observed_uptime_ms'] < 0 || $m['observed_uptime_ms'] > $event['uptime_ms'] || !$this->timestamp($m['observed_at'] ?? null))) return 'invalid_sample_identity';
        }
        if (array_key_exists('gps',$event['payload'])) {
            $gps = $event['payload']['gps'];
            if (!is_array($gps) || !is_bool($gps['fix_valid'] ?? null)) return 'invalid_gps';
            if (array_key_exists('quality',$gps) && !in_array($gps['quality'],['valid','estimated','unsupported','unavailable','stale','ambiguous','invalid'],true)) return 'invalid_gps';
            if ($gps['fix_valid']) {
                foreach (['latitude'=>[-90,90], 'longitude'=>[-180,180], 'heading_deg'=>[0,359.999999], 'horizontal_accuracy_m'=>[0,100000], 'speed_kmh'=>[0,250]] as $key=>[$min,$max]) {
                    $v = $gps[$key] ?? null;
                    if ($v === null && !in_array($key, ['latitude','longitude'], true)) continue;
                    if ((!is_int($v) && !is_float($v)) || !is_finite((float) $v) || $v < $min || $v > $max) return 'invalid_gps_range';
                }
                if (!Str::isUuid($gps['sample_id'] ?? '') || !$this->timestamp($gps['observed_at'] ?? null) || !is_int($gps['observed_uptime_ms'] ?? null) || $gps['observed_uptime_ms'] < 0 || $gps['observed_uptime_ms'] > $event['uptime_ms']) return 'invalid_gps_sample';
            }
        }
        return null;
    }

    private function timestamp(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) return false;
        try { $at = CarbonImmutable::parse($value); return $at->format('Y-m-d\TH:i:s') === substr($value, 0, 19); } catch (\Throwable $e) { return false; }
    }
}
