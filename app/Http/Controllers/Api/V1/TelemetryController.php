<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Permissions;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TelemetryController
{
    private function query(Request $request, string $organization, string $vehicle)
    {
        abort_unless(app(Permissions::class)->allows($request->attributes->get('membership'), 'telemetry.read'), 403);
        abort_unless(DB::table('vehicles')->where('organization_id',$organization)->where('id',$vehicle)->exists(),404);
        $filter = $request->validate(['from'=>'required|date','to'=>'required|date|after:from','per_page'=>'nullable|integer|min:1|max:200','cursor'=>'nullable|string|max:512']);
        $from = CarbonImmutable::parse($filter['from'])->utc(); $to = CarbonImmutable::parse($filter['to'])->utc();
        if ($from->diffInSeconds($to) > 86400) throw ValidationException::withMessages(['to'=>['Raw queries are limited to 24 hours.']]);
        return DB::table('telemetry_samples')->where('organization_id',$organization)->where('vehicle_id',$vehicle)->where('occurred_at','>=',$from)->where('occurred_at','<',$to)->orderBy('occurred_at')->orderBy('id');
    }

    public function samples(Request $request, string $organization, string $vehicle)
    {
        $page = $this->query($request,$organization,$vehicle)->cursorPaginate((int)$request->input('per_page',50), ['id','occurred_at','received_at','speed_kmh','engine_rpm','fuel_rate_lph','latitude','longitude','vehicle_data','behaviour_data','health_data','quality','source_format']);
        $privacy = app(\App\Services\DtcPrivacy::class);
        foreach ($page->items() as $s) {
            foreach (['quality','vehicle_data','behaviour_data','health_data'] as $k) {
                if (is_string($s->$k ?? null)) {
                    $s->$k = $privacy->scrub(json_decode($s->$k, true, 32, JSON_THROW_ON_ERROR));
                }
            }
        }
        return ApiResponse::success($request,['items'=>$page->items(),'pagination'=>['next_cursor'=>$page->nextCursor()?->encode(),'per_page'=>$page->perPage()],'links'=>['next'=>$page->nextPageUrl()]],'Telemetry Retrieved');
    }

    public function positions(Request $request, string $organization, string $vehicle)
    {
        $page = $this->query($request,$organization,$vehicle)->cursorPaginate((int)$request->input('per_page',50), ['id','occurred_at','position_observed_at','latitude','longitude','speed_kmh','engine_rpm']);
        return ApiResponse::success($request,['items'=>$page->items(),'pagination'=>['next_cursor'=>$page->nextCursor()?->encode(),'per_page'=>$page->perPage()],'links'=>['next'=>$page->nextPageUrl()],'meta'=>['missing_positions_are_gaps'=>true]],'Positions Retrieved');
    }
}
