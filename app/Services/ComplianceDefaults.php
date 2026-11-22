<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ComplianceDefaults
{
    public const DEFAULTS = [
        ['code' => 'roadtax', 'name' => 'Road Tax', 'subject_type' => 'vehicle', 'warning_days' => 30],
        ['code' => 'insurance', 'name' => 'Insurance', 'subject_type' => 'vehicle', 'warning_days' => 30],
        ['code' => 'puspakom', 'name' => 'PUSPAKOM', 'subject_type' => 'vehicle', 'warning_days' => 30],
    ];

    /**
     * Ensure default compliance types (roadtax, insurance, puspakom) exist for the organization.
     */
    public function ensureTypes(string $organizationId): void
    {
        foreach (self::DEFAULTS as $type) {
            $exists = DB::table('compliance_types')
                ->where('organization_id', $organizationId)
                ->where('code', $type['code'])
                ->exists();
            if (!$exists) {
                DB::table('compliance_types')->insert([
                    'id' => (string) Str::uuid(),
                    'organization_id' => $organizationId,
                    'code' => $type['code'],
                    'name' => $type['name'],
                    'subject_type' => $type['subject_type'],
                    'warning_days' => $type['warning_days'],
                    'active' => true,
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Ensure default obligations for roadtax, insurance, puspakom exist for a vehicle.
     */
    public function ensureVehicleObligations(string $organizationId, string $vehicleId): void
    {
        $this->ensureTypes($organizationId);
        $types = DB::table('compliance_types')
            ->where('organization_id', $organizationId)
            ->where('subject_type', 'vehicle')
            ->where('active', true)
            ->get();

        foreach ($types as $type) {
            $exists = DB::table('compliance_obligations')
                ->where('organization_id', $organizationId)
                ->where('vehicle_id', $vehicleId)
                ->where('compliance_type_id', $type->id)
                ->exists();
            if (!$exists) {
                DB::table('compliance_obligations')->insert([
                    'id' => (string) Str::uuid(),
                    'organization_id' => $organizationId,
                    'compliance_type_id' => $type->id,
                    'subject_type' => 'vehicle',
                    'vehicle_id' => $vehicleId,
                    'applicability' => 'applicable',
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
