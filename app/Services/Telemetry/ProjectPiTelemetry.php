<?php

namespace App\Services\Telemetry;

use App\Services\ActionAudit;
use App\Services\DriverShiftService;
use App\Services\Journeys\JourneyDetector;
use App\Services\Mileage\SpeedLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectPiTelemetry
{
    /** Called inside ProjectAcceptedTelemetry's tenant/vehicle/inbox transaction and locks. */
    public function projectLocked(object $inbox, object $installation, array $event): string
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Projection requires a transaction.');
        $at = CarbonImmutable::parse($inbox->occurred_at)->utc();
        $packetAt = isset($event['timestamp']) ? CarbonImmutable::parse($event['timestamp'])->utc() : $at;
        $vehicle = DB::table('vehicles')->where('organization_id',$inbox->organization_id)->where('id',$installation->vehicle_id)->first();
        $v = $event['vehicle'] ?? []; $gps = $event['gps'] ?? [];
        $position = ($gps['fix_valid'] ?? false) === true && isset($gps['timestamp'],$gps['latitude'],$gps['longitude']);
        if ($position) {
            $gpsAt = CarbonImmutable::parse($gps['timestamp'])->utc(); $age = $gpsAt->diffInSeconds($packetAt, false);
            $position = $age >= 0 && $age <= 5 && $gpsAt->gte(CarbonImmutable::parse($installation->effective_from))
                && (!$installation->effective_to || $gpsAt->lt(CarbonImmutable::parse($installation->effective_to)));
        }
        $obd = ($v['obd_connected'] ?? false) === true;
        $fuel = $obd ? ($v['fuel_rate_lph'] ?? null) : null; $fuelSource = $fuel !== null ? 'direct_packet_snapshot' : null;
        if ($fuel === null && $obd && $vehicle->fuel_type === 'petrol' && isset($v['maf_gps'])) {
            $settings=json_decode(DB::table('organization_settings')->where('organization_id',$inbox->organization_id)->value('settings') ?? '{}',true);
            $fuel = $v['maf_gps'] * 3600 / (($settings['maf_air_fuel_ratio'] ?? 14.7) * ($settings['maf_fuel_density_g_per_litre'] ?? 745)); $fuelSource = 'maf_petrol_estimate';
        }
        // The Pi's totals and flags are retained as claims; totals never become the server fuel ledger or safety scores.
        $sampleId = (string)Str::uuid();
        $shiftId = app(DriverShiftService::class)->resolveShiftForTelemetry($inbox->organization_id, $vehicle->id, $installation->id, $at);
        DB::table('telemetry_samples')->insert(['id'=>$sampleId,'organization_id'=>$inbox->organization_id,'vehicle_id'=>$vehicle->id,'installation_id'=>$installation->id,'inbox_id'=>$inbox->id,
            'occurred_at'=>$at,'received_at'=>$inbox->received_at,'speed_kmh'=>$obd ? ($v['speed_kmh'] ?? null) : null,'engine_rpm'=>$obd ? ($v['engine_rpm'] ?? null) : null,
            'latitude'=>$position ? $gps['latitude'] : null,'longitude'=>$position ? $gps['longitude'] : null,'position_observed_at'=>$position ? $gpsAt : null,
            'source_format'=>'pi_snapshot_v1','vehicle_data'=>json_encode($v,JSON_THROW_ON_ERROR),'network_data'=>json_encode($event['network'] ?? new \stdClass(),JSON_THROW_ON_ERROR),
            'health_data'=>json_encode($event['device_health'] ?? new \stdClass(),JSON_THROW_ON_ERROR),'behaviour_data'=>json_encode($event['driving_behaviour'] ?? new \stdClass(),JSON_THROW_ON_ERROR),
            'authorized_shift_id'=>$shiftId,'fuel_rate_lph'=>$fuel,'fuel_rate_source'=>$fuelSource,
            'quality'=>json_encode(['clock'=>'unreported','vehicle'=>'packet_snapshot','gps'=>$position ? 'reported_fix' : 'unusable','behaviour'=>'unverified_edge_claim','fuel'=>$fuelSource ?? 'unknown',
                'maf_assumptions'=>$fuelSource === 'maf_petrol_estimate' ? ['air_fuel_ratio'=>$settings['maf_air_fuel_ratio'] ?? 14.7,'density_g_per_litre'=>$settings['maf_fuel_density_g_per_litre'] ?? 745] : null],JSON_THROW_ON_ERROR),'created_at'=>now()]);
        $latest = DB::table('vehicle_latest_states')->where('organization_id',$inbox->organization_id)->where('vehicle_id',$vehicle->id)->first();
        if (!$latest || $at->gt(CarbonImmutable::parse($latest->occurred_at))) {
            $data=['sample_id'=>$sampleId,'occurred_at'=>$at,'received_at'=>$inbox->received_at,'version'=>($latest->version ?? 0)+1];
            if ($latest) DB::table('vehicle_latest_states')->where('organization_id',$inbox->organization_id)->where('vehicle_id',$vehicle->id)->update($data);
            else DB::table('vehicle_latest_states')->insert(['organization_id'=>$inbox->organization_id,'vehicle_id'=>$vehicle->id]+$data);
        }
        app(\App\Services\Energy\PacketFuelLedger::class)->connect($sampleId);
        app(\App\Services\SafetyProjection::class)->connect($sampleId);
        $distance = app(SpeedLedger::class)->project($inbox->id); $journey='unusable';
        if (in_array($distance,['stored','duplicate'],true)) {
            $observation = DB::table('mileage_speed_observations')->where('inbox_id',$inbox->id)->first();
            $journey = app(JourneyDetector::class)->project($observation->id);
        }
        DB::table('telemetry_inbox')->where('id',$inbox->id)->update(['state'=>$journey === 'requires_rebuild' ? 'projected_requires_rebuild' : 'projected','projected_at'=>now(),'projection_error'=>null]);
        app(\App\Services\Maintenance\EvaluateMaintenance::class)->vehicle($inbox->organization_id,$vehicle->id);
        $audit=app(ActionAudit::class);
        $audit->outbox($audit->event('telemetry.project','succeeded','scheduler',['organization_id'=>$inbox->organization_id,'source_event_id'=>$inbox->event_id,'inbox_id'=>$inbox->id,'source_format'=>'pi_snapshot_v1','mileage_outcome'=>$distance,'journey_outcome'=>$journey,'browser_absence_reason'=>'non_browser_execution']));
        return $journey === 'requires_rebuild' ? 'requires_rebuild' : 'projected';
    }
}
