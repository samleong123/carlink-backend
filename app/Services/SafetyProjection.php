<?php
namespace App\Services;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Speed-difference observations, not calibrated IMU events. Edge harsh-driving flags never affect scores. */
class SafetyProjection
{
    public function connect(string $id): void
    {
        $s=DB::table('telemetry_samples')->where('id',$id)->first();
        $e=app(Entitlements::class);
        if (config('saas.enforce_subscriptions') && !in_array($e->plan($s->organization_id)['safety_enabled'] ?? false,[true,1],true)) return;
        $maxGap=$e->maxCompatibleGapSeconds($s->organization_id);
        $settings=json_decode(DB::table('organization_settings')->where('organization_id',$s->organization_id)->value('settings') ?? '{}',true);
        $q=fn()=>DB::table('telemetry_samples')->where('organization_id',$s->organization_id)->where('vehicle_id',$s->vehicle_id)->where('installation_id',$s->installation_id);
        $previous=$q()->where('occurred_at','<',$s->occurred_at)->orderByDesc('occurred_at')->first();
        $next=$q()->where('occurred_at','>',$s->occurred_at)->orderBy('occurred_at')->first();
        if ($previous && $next) DB::table('safety_events')->where('start_sample_id',$previous->id)->where('end_sample_id',$next->id)->update(['state'=>'superseded','updated_at'=>now()]);
        foreach ([[$previous,$s],[$s,$next]] as [$a,$b]) {
            if (!$a || !$b || $a->speed_kmh === null || $b->speed_kmh === null || $a->source_format !== $b->source_format) continue;
            $dt=CarbonImmutable::parse($a->occurred_at)->diffInSeconds(CarbonImmutable::parse($b->occurred_at),false);
            if ($dt<=0 || $dt>$maxGap) continue;
            $acceleration=((float)$b->speed_kmh-(float)$a->speed_kmh)/3.6/$dt;
            $threshold=$acceleration<0 ? ($settings['harsh_braking_ms2'] ?? 3.0) : ($settings['rapid_acceleration_ms2'] ?? 3.0);
            if (abs($acceleration)<$threshold) continue;
            $shift=$a->authorized_shift_id && $a->authorized_shift_id === $b->authorized_shift_id ? DB::table('driver_shifts')->where('organization_id',$s->organization_id)->where('id',$a->authorized_shift_id)->first() : null;
            DB::table('safety_events')->insertOrIgnore(['id'=>(string)Str::uuid(),'organization_id'=>$s->organization_id,'vehicle_id'=>$s->vehicle_id,'driver_id'=>$shift?->driver_id,'shift_id'=>$shift?->id,
                'start_sample_id'=>$a->id,'end_sample_id'=>$b->id,'kind'=>$acceleration<0 ? 'harsh_braking' : 'rapid_acceleration','acceleration_ms2'=>$acceleration,'threshold_ms2'=>$threshold,
                'occurred_at'=>$b->occurred_at,'quality'=>'uncalibrated_speed_difference','state'=>'observed','created_at'=>now(),'updated_at'=>now()]);
        }
    }
}
