<?php

namespace App\Services\Telemetry;

use App\Services\ActionAudit;
use App\Services\DriverShiftService;
use App\Services\Journeys\JourneyDetector;
use App\Services\Mileage\SpeedLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectAcceptedTelemetry
{
    public function project(string $inboxId): string
    {
        $probe = DB::table('telemetry_inbox')->where('id', $inboxId)->first() ?? throw new \InvalidArgumentException('Unknown inbox.');
        return DB::transaction(function () use ($probe) {
            DB::table('organizations')->where('id',$probe->organization_id)->lockForUpdate()->first();
            $installation = DB::table('device_installations')->where('organization_id',$probe->organization_id)->where('id',$probe->installation_id)->first() ?? throw new \DomainException('Missing historical installation.');
            DB::table('vehicles')->where('organization_id',$probe->organization_id)->where('id',$installation->vehicle_id)->lockForUpdate()->first();
            $inbox = DB::table('telemetry_inbox')->where('id',$probe->id)->lockForUpdate()->first();
            if ($inbox->projected_at) return 'duplicate';
            $event = json_decode($inbox->raw_payload, true, 32, JSON_THROW_ON_ERROR);
            if ($inbox->source_format === 'pi_snapshot_v1') return app(ProjectPiTelemetry::class)->projectLocked($inbox, $installation, $event);
            $quality = []; $values = [];
            foreach (['speed_kmh','engine_rpm'] as $key) {
                $metric = $event['payload']['vehicle'][$key] ?? [];
                $quality[$key] = $metric['quality'] ?? 'unavailable';
                $values[$key] = $this->fresh($metric, $event, $installation) ? $metric['value'] : null;
                $quality[$key.'_projection'] = $values[$key] === null ? 'unusable' : 'valid';
            }
            $gps = $event['payload']['gps'] ?? [];
            $position = ($gps['fix_valid'] ?? false) === true && ($gps['quality'] ?? null) === 'valid' && $this->fresh(['value'=>1] + $gps, $event, $installation);
            $sampleId = (string) Str::uuid();
            $shiftId = app(DriverShiftService::class)->resolveShiftForTelemetry(
                $inbox->organization_id,
                $installation->vehicle_id,
                $installation->id,
                CarbonImmutable::parse($inbox->occurred_at)->utc()
            );
            DB::table('telemetry_samples')->insert(['id'=>$sampleId,'organization_id'=>$inbox->organization_id,'vehicle_id'=>$installation->vehicle_id,'installation_id'=>$installation->id,'inbox_id'=>$inbox->id,
                'occurred_at'=>$inbox->occurred_at,'received_at'=>$inbox->received_at,'speed_kmh'=>$values['speed_kmh'],'engine_rpm'=>$values['engine_rpm'],
                'latitude'=>$position ? $gps['latitude'] : null,'longitude'=>$position ? $gps['longitude'] : null,'position_observed_at'=>$position ? CarbonImmutable::parse($gps['observed_at'])->utc() : null,
                'authorized_shift_id'=>$shiftId,'quality'=>json_encode($quality + ['gps'=>$gps['quality'] ?? 'unavailable'], JSON_THROW_ON_ERROR),'created_at'=>now()]);
            app(\App\Services\SafetyProjection::class)->connect($sampleId);
            $latest = DB::table('vehicle_latest_states')->where('organization_id',$inbox->organization_id)->where('vehicle_id',$installation->vehicle_id)->first();
            if ($this->clockUsable($event) && (!$latest || CarbonImmutable::parse($inbox->occurred_at)->gt(CarbonImmutable::parse($latest->occurred_at)))) {
                $data = ['sample_id'=>$sampleId,'occurred_at'=>$inbox->occurred_at,'received_at'=>$inbox->received_at,'version'=>($latest->version ?? 0)+1];
                if ($latest) DB::table('vehicle_latest_states')->where('organization_id',$inbox->organization_id)->where('vehicle_id',$installation->vehicle_id)->update($data);
                else DB::table('vehicle_latest_states')->insert(['organization_id'=>$inbox->organization_id,'vehicle_id'=>$installation->vehicle_id] + $data);
            }
            $distance = app(SpeedLedger::class)->project($inbox->id);
            $journey = 'unusable';
            if (in_array($distance, ['stored','duplicate'], true)) {
                $observation = DB::table('mileage_speed_observations')->where('device_id',$inbox->device_id)->where('boot_id',$inbox->boot_id)->where('sample_id',$event['payload']['vehicle']['speed_kmh']['sample_id'])->first();
                $journey = app(JourneyDetector::class)->project($observation->id);
            }
            DB::table('telemetry_inbox')->where('id',$inbox->id)->update(['state'=>$journey === 'requires_rebuild' ? 'projected_requires_rebuild' : 'projected', 'projected_at'=>now(),'projection_error'=>null]);
            app(\App\Services\Maintenance\EvaluateMaintenance::class)->vehicle($inbox->organization_id,$installation->vehicle_id);
            $audit = app(ActionAudit::class);
            $audit->outbox($audit->event('telemetry.project', 'succeeded', 'scheduler', ['organization_id'=>$inbox->organization_id,'source_event_id'=>$inbox->event_id,'inbox_id'=>$inbox->id,'mileage_outcome'=>$distance,'journey_outcome'=>$journey,'browser_absence_reason'=>'non_browser_execution']));
            return $journey === 'requires_rebuild' ? 'requires_rebuild' : 'projected';
        });
    }

    private function fresh(array $metric, array $event, object $installation): bool
    {
        if (!$this->clockUsable($event) || ($metric['quality'] ?? null) !== 'valid' || !isset($metric['value'], $metric['observed_at'])) return false;
        try {
            $at=CarbonImmutable::parse($metric['observed_at']);
            if ($at->lt(CarbonImmutable::parse($installation->effective_from)) || ($installation->effective_to && $at->gte(CarbonImmutable::parse($installation->effective_to)))) return false;
            $age = $at->diffInSeconds(CarbonImmutable::parse($event['occurred_at']), false);
        }
        catch (\Throwable $e) { return false; }
        return $age >= 0 && $age <= 5;
    }

    private function clockUsable(array $event): bool
    {
        $error=$event['clock']['estimated_error_ms'] ?? null;
        return ($event['clock']['synchronized'] ?? null) === true && (is_int($error) || is_float($error)) && $error >= 0 && $error <= 5000;
    }
}
