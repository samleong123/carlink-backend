<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MileageController
{
    private function authorize(Request $request, bool $write = false): void
    {
        abort_unless(app(\App\Services\Permissions::class)->allows($request->attributes->get('membership'), $write ? 'mileage.correct' : 'mileage.read'), 403);
    }
    private function vehicle(string $org, string $id, bool $lock = false): object
    {
        $query = DB::table('vehicles')->where('organization_id',$org)->where('id',$id);
        return ($lock ? $query->lockForUpdate() : $query)->first() ?? abort(404);
    }

    public function summary(Request $request, string $organization, string $vehicle)
    {
        $this->authorize($request); $this->vehicle($organization,$vehicle);
        $query = fn () => DB::table('vehicle_distance_segments')->where('organization_id',$organization)->where('vehicle_id',$vehicle)->whereNull('superseded_at');
        $baseline=DB::table('odometer_observations')->where('organization_id',$organization)->where('vehicle_id',$vehicle)->first();
        $total=(float) ($query()->whereNotNull('distance_m')->sum('distance_m') ?? 0);
        $data=['accepted_distance_m'=>(int) round($total), 'estimated_odometer_m'=>null, 'distance_since_baseline_m'=>null,
            'baseline'=>$baseline ? ['reading_m'=>(int)$baseline->reading_m,'observed_at'=>$baseline->observed_at] : null,
            'unknown_interval_count'=>$query()->whereNull('distance_m')->count(), 'as_of'=>$query()->max('to_at'),
            'quality'=>'estimated_partial_coverage', 'driver_allocation'=>'not_implemented', 'coverage_complete'=>false,
            'distance_sources'=>$query()->whereNotNull('distance_m')->distinct()->pluck('source')->all(),
            'packet_snapshot_distance_m'=>(float)$query()->where('source','packet_speed')->sum('distance_m')];
        if ($baseline) {
            $window=app(\App\Services\Mileage\DistanceWindow::class)->summarize($organization,$vehicle,CarbonImmutable::parse($baseline->observed_at)->utc(),CarbonImmutable::now('UTC'));
            $data['distance_since_baseline_m']=(int) round($window['distance_m']);
            $data['estimated_odometer_m']=(int) $baseline->reading_m + $data['distance_since_baseline_m'];
            $data['baseline_alignment']='integrated_segment_boundary';
        }
        return ApiResponse::success($request,$data,'Vehicle Mileage Retrieved','Mileage is an estimate; unobserved intervals are not zero travel.');
    }

    public function segments(Request $request, string $organization, string $vehicle)
    {
        $this->authorize($request); $this->vehicle($organization,$vehicle);
        $validated=$request->validate(['from'=>'nullable|date', 'to'=>'nullable|date|after:from', 'limit'=>'nullable|integer|min:1|max:100']);
        $q=DB::table('vehicle_distance_segments')->where('organization_id',$organization)->where('vehicle_id',$vehicle)->whereNull('superseded_at');
        if (!empty($validated['from'])) $q->where('to_at','>',CarbonImmutable::parse($validated['from'])->utc());
        if (!empty($validated['to'])) $q->where('from_at','<',CarbonImmutable::parse($validated['to'])->utc());
        $items=$q->orderByDesc('from_at')->limit($validated['limit'] ?? 100)->get(['id','from_at','to_at','distance_m','source','quality','algorithm']);
        return ApiResponse::success($request,['items'=>$items],'Mileage Segments Retrieved');
    }

    public function baseline(Request $request, string $organization, string $vehicle)
    {
        $this->authorize($request,true);
        $data=$request->validate(['reading_m'=>'required|integer|min:0|max:10000000000', 'observed_at'=>'required|date|before_or_equal:now', 'reason'=>'required|string|min:3|max:500']);
        $observed=CarbonImmutable::parse($data['observed_at'])->utc();
        $id=DB::transaction(function () use ($request,$organization,$vehicle,$data,$observed) {
            $this->vehicle($organization,$vehicle,true);
            if (DB::table('odometer_observations')->where('organization_id',$organization)->where('vehicle_id',$vehicle)->exists()) throw ValidationException::withMessages(['baseline'=>['A baseline already exists. Calibration adjustments are not yet available.']]);
            $id=(string) Str::uuid();
            DB::table('odometer_observations')->insert(['id'=>$id,'organization_id'=>$organization,'vehicle_id'=>$vehicle,'created_by'=>$request->user()->id,'observed_at'=>$observed->format('Y-m-d H:i:s.uP'),'reading_m'=>$data['reading_m'],'reason'=>$data['reason'],'created_at'=>now()]);
            return $id;
        });
        return ApiResponse::success($request,['id'=>$id],'Odometer Baseline Recorded','The initial reading is an anchor, not distance travelled.',201);
    }
}
