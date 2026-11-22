<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class EmissionFactors
{
    public const DEFAULT_PETROL_KG_PER_LITRE = 2.31;
    public const DEFAULT_DIESEL_KG_PER_LITRE = 2.68;

    /**
     * Resolve tank-to-wheel CO2 emission factor (kg CO2 per litre) from organization
     * settings with standard fuel-type defaults when unconfigured.
     *
     * @return array{fuel_type:string,kg_per_litre:?float,source:string,label:string}
     */
    public static function resolve(string $organizationId, ?string $fuelType, ?array $settings = null): array
    {
        if ($settings === null) {
            $raw = DB::table('organization_settings')->where('organization_id', $organizationId)->value('settings');
            $settings = $raw ? (json_decode($raw, true) ?: []) : [];
        }

        $normalized = strtolower(trim((string) ($fuelType ?: 'petrol')));
        if ($normalized === '') {
            $normalized = 'petrol';
        }

        if ($normalized === 'electric' || $normalized === 'ev' || $normalized === 'bev') {
            return [
                'fuel_type' => $normalized,
                'kg_per_litre' => 0.0,
                'source' => 'zero_tailpipe',
                'label' => '0 kg CO2/L (electric · zero_tailpipe)',
            ];
        }

        if ($normalized === 'diesel') {
            $configured = $settings['co2_diesel_kg_per_litre'] ?? null;
            if (is_numeric($configured) && (float) $configured > 0) {
                $factor = round((float) $configured, 4);
                return [
                    'fuel_type' => 'diesel',
                    'kg_per_litre' => $factor,
                    'source' => 'organization_settings',
                    'label' => "{$factor} kg CO2/L (diesel · organization_settings)",
                ];
            }
            return [
                'fuel_type' => 'diesel',
                'kg_per_litre' => self::DEFAULT_DIESEL_KG_PER_LITRE,
                'source' => 'standard_default',
                'label' => self::DEFAULT_DIESEL_KG_PER_LITRE.' kg CO2/L (diesel · standard_default)',
            ];
        }

        if ($normalized === 'petrol' || $normalized === 'gasoline' || $normalized === 'hybrid') {
            $configured = $settings['co2_petrol_kg_per_litre'] ?? null;
            if (is_numeric($configured) && (float) $configured > 0) {
                $factor = round((float) $configured, 4);
                return [
                    'fuel_type' => $normalized,
                    'kg_per_litre' => $factor,
                    'source' => 'organization_settings',
                    'label' => "{$factor} kg CO2/L ({$normalized} · organization_settings)",
                ];
            }
            return [
                'fuel_type' => $normalized,
                'kg_per_litre' => self::DEFAULT_PETROL_KG_PER_LITRE,
                'source' => 'standard_default',
                'label' => self::DEFAULT_PETROL_KG_PER_LITRE." kg CO2/L ({$normalized} · standard_default)",
            ];
        }

        return [
            'fuel_type' => $normalized,
            'kg_per_litre' => null,
            'source' => 'not_configured',
            'label' => "not_configured ({$normalized})",
        ];
    }
}
