<?php
namespace App\Http\Controllers\Api\V1;
use App\Services\Entitlements;
use App\Services\Permissions;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SafetyController
{
    private function allow(Request $r, string $org, string $key): void { abort_unless(app(Permissions::class)->allows($r->attributes->get('membership'),$key),403); app(Entitlements::class)->feature($org,'safety'); }
    public function events(Request $r, string $organization)
    {
        $this->allow($r,$organization,'safety.read');
        $v=$r->validate(['driver_id'=>'nullable|uuid','vehicle_id'=>'nullable|uuid','per_page'=>'nullable|integer|min:1|max:100','cursor'=>'nullable|string|max:512']);
        $p=DB::table('safety_events')
            ->join('telemetry_samples as end_s','end_s.id','=','safety_events.end_sample_id')
            ->join('telemetry_samples as start_s','start_s.id','=','safety_events.start_sample_id')
            ->leftJoin('vehicles as v',fn($j)=>$j->on('v.id','=','safety_events.vehicle_id')->on('v.organization_id','=','safety_events.organization_id'))
            ->where('safety_events.organization_id',$organization)
            ->where('safety_events.occurred_at','>=',app(Entitlements::class)->cutoff($organization))
            ->where('safety_events.state','<>','superseded')
            ->when($v['driver_id'] ?? null,fn($q,$id)=>$q->where('safety_events.driver_id',$id))
            ->when($v['vehicle_id'] ?? null,fn($q,$id)=>$q->where('safety_events.vehicle_id',$id))
            ->select([
                'safety_events.*',
                DB::raw('COALESCE(end_s.latitude, start_s.latitude) as latitude'),
                DB::raw('COALESCE(end_s.longitude, start_s.longitude) as longitude'),
                'start_s.latitude as start_latitude',
                'start_s.longitude as start_longitude',
                'end_s.latitude as end_latitude',
                'end_s.longitude as end_longitude',
                'start_s.speed_kmh as start_speed_kmh',
                'end_s.speed_kmh as end_speed_kmh',
                DB::raw('CASE WHEN end_s.speed_kmh IS NOT NULL AND start_s.speed_kmh IS NOT NULL THEN (end_s.speed_kmh - start_s.speed_kmh) ELSE NULL END as delta_speed_kmh'),
                'v.registration_number',
                'v.make as vehicle_make',
                'v.model as vehicle_model',
            ])
            ->orderBy('safety_events.id')
            ->cursorPaginate($v['per_page'] ?? 50);
        return ApiResponse::success($r,['items'=>$p->items(),'pagination'=>['next_cursor'=>$p->nextCursor()?->encode()]],'Safety Observations Retrieved','Uncalibrated observations, not disciplinary findings.');
    }
    public function appeals(Request $r, string $organization)
    {
        $this->allow($r,$organization,'safety.appeals.decide');
        $p=DB::table('safety_appeals')->where('organization_id',$organization)->where('created_at','>=',app(Entitlements::class)->cutoff($organization))->orderBy('id')->cursorPaginate(50);
        return ApiResponse::success($r,['items'=>$p->items(),'pagination'=>['next_cursor'=>$p->nextCursor()?->encode()]],'Safety Appeals Retrieved');
    }
    public function decide(Request $r, string $organization, string $appeal)
    {
        $this->allow($r,$organization,'safety.appeals.decide');
        $v=$r->validate(['version'=>'required|integer|min:1','decision'=>'required|in:approved,rejected','reason'=>'required|string|max:2000']);
        DB::table('organizations')->where('id',$organization)->lockForUpdate()->first();
        $row=DB::table('safety_appeals')->where('organization_id',$organization)->where('id',$appeal)->where('created_at','>=',app(Entitlements::class)->cutoff($organization))->lockForUpdate()->first() ?? abort(404);
        abort_if($row->submitted_by === $r->user()->id,403,'You cannot review your own appeal. Ask another authorized reviewer.');
        abort_unless($row->status === 'pending' && (int)$row->version === $v['version'],409);
        DB::table('safety_appeals')->where('id',$appeal)->update(['status'=>$v['decision'],'decided_by'=>$r->user()->id,'decision_reason'=>$v['reason'],'version'=>$row->version+1,'updated_at'=>now()]);
        if ($v['decision'] === 'approved') DB::table('safety_events')->where('organization_id',$organization)->where('id',$row->event_id)->where('state','observed')->update(['state'=>'excluded','updated_at'=>now()]);
        app(\App\Services\Mail\NotificationEmails::class)->queue('appeal.decided',$appeal.':'.($row->version+1),$row->submitted_by,$organization,'Your appeal was '.$v['decision'],'A compliance-authorized reviewer has decided your appeal.',
            ['Appeal reference'=>$appeal,'Decision'=>$v['decision'],'Reviewer’s explanation'=>$v['reason']],['permissions'=>['profile.own'],'driver_id'=>$row->driver_id,'safety_required'=>true,'subscription_required'=>false,'period_start'=>$row->created_at],null,
            $v['decision']==='approved' ? 'The approved observation is excluded from the experimental score. Historical data remains subject to retention.' : 'The observation remains included. The score is experimental, not a disciplinary finding.');
        return ApiResponse::success($r,['version'=>$row->version+1],'Appeal Decided');
    }
    public function score(Request $r, string $organization, string $driver)
    {
        $this->allow($r,$organization,'safety.read');
        abort_unless(DB::table('drivers')->where('organization_id',$organization)->where('id',$driver)->exists(),404);
        return ApiResponse::success($r,$this->scoreData($organization,$driver),'Driver Safety Score Retrieved');
    }
    public function scoreData(string $org, string $driver): array
    {
        $entitlements=app(Entitlements::class);
        $cut=$entitlements->cutoff($org);
        $maxGap=$entitlements->maxCompatibleGapSeconds($org);
        $retentionDays=$entitlements->plan($org)['retention_days'] ?? 30;
        $count=DB::table('safety_events')->where('organization_id',$org)->where('driver_id',$driver)->where('occurred_at','>=',$cut)->where('state','observed')->count();
        $shiftIds=DB::table('driver_shifts')->where('organization_id',$org)->where('driver_id',$driver)->select('id');
        $samples=0; $intervals=0; $previous=null;
        foreach (DB::table('telemetry_samples')->where('organization_id',$org)->whereIn('authorized_shift_id',$shiftIds)->where('occurred_at','>=',$cut)->orderBy('occurred_at')->orderBy('id')->cursor() as $s) {
            if ($s->speed_kmh !== null) $samples++;
            if ($previous && $s->speed_kmh !== null && $previous->speed_kmh !== null && $s->authorized_shift_id === $previous->authorized_shift_id && $s->installation_id === $previous->installation_id && $s->source_format === $previous->source_format) {
                $dt=\Carbon\CarbonImmutable::parse($previous->occurred_at)->diffInSeconds(\Carbon\CarbonImmutable::parse($s->occurred_at),false);
                if ($dt>0 && $dt<=$maxGap) $intervals++;
            }
            $previous=$s;
        }
        return ['score'=>$intervals<1 ? null : max(0,100-5*$count),'events_included'=>$count,'observed_speed_samples'=>$samples,'eligible_speed_intervals'=>$intervals,'algorithm'=>'experimental_100_minus_5_per_observation_v1','quality'=>'uncalibrated_not_distance_normalized','from'=>$cut->toIso8601String(),'to'=>now()->toIso8601String(),'approved_appeals_excluded'=>true,'retention_days'=>$retentionDays,'max_compatible_gap_seconds'=>$maxGap];
    }
}
