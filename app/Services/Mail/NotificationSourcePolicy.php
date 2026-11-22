<?php
namespace App\Services\Mail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Recheck source state immediately before transport, not only at the next scheduler tick. */
class NotificationSourcePolicy
{
    public function eligible(object $row): bool
    {
        $org=$row->organization_id;
        if ($row->resource_type ?? null) {
            $tables=['subscription'=>'organization_subscriptions','invoice'=>'billing_invoices','installation'=>'device_installations','appeal'=>'safety_appeals'];
            if (!isset($tables[$row->resource_type])) return false;
            $q=DB::table($tables[$row->resource_type])->where('organization_id',$org);
            if ($row->resource_type==='subscription') $q->where('organization_id',$row->resource_id); else $q->where('id',$row->resource_id);
            $source=$q->first(); if (!$source) return false;
            if ($row->resource_version!==null && (int)$source->version!==(int)$row->resource_version) return false;
            if ($row->resource_type==='invoice' && $row->event_key==='invoice.issued' && $source->status!=='issued') return false;
            if ($row->resource_type==='subscription' && $row->event_key==='subscription.expiry' && $source->status!=='active') return false;
            if ($row->resource_type==='appeal' && $row->event_key==='appeal.review_requested' && $source->status!=='pending') return false;
            if ($row->resource_type==='installation' && ($source->effective_to!==null || !DB::table('devices')->where('organization_id',$org)->where('id',$source->device_id)->where('status','active')->exists())) return false;
        }
        if (!$row->alert_id) return true;
        $alert=DB::table('alert_occurrences')->where('organization_id',$org)->where('id',$row->alert_id)->where('state','open')->first(); if (!$alert) return false;
        $vehicle=DB::table('vehicles')->where('organization_id',$org)->where('id',$alert->vehicle_id)->first();
        if (!$vehicle || !in_array($vehicle->status,['active','maintenance'],true)) return false;
        if ($alert->kind==='compliance.threshold_crossed') {
            $obligation=DB::table('compliance_obligations')->where('organization_id',$org)->where('id',substr($alert->rule_key,strlen('compliance:')))->where('vehicle_id',$vehicle->id)->where('applicability','applicable')->first();
            if (!$obligation || $vehicle->status!=='active') return false;
            $type=DB::table('compliance_types')->where('organization_id',$org)->where('id',$obligation->compliance_type_id)->where('active',true)->where('version',$alert->rule_version)->first(); if (!$type) return false;
            $status=app(\App\Http\Controllers\Api\V1\ComplianceController::class)->evaluate($org,$obligation);
            if ($alert->episode_key!=='record:'.$status['record_id']) return false;
            if (!DB::table('compliance_records')->where('organization_id',$org)->where('obligation_id',$obligation->id)->where('id',$status['record_id'])->where('state','verified')
                ->where(fn($q)=>$q->whereNull('suspended_at')->orWhere('suspended_at','>',now()))->exists()) return false;
            if (str_starts_with($alert->threshold_key,'expired')) return $status['status']==='expired';
            if ($status['status']!=='due_soon') return false;
            $date=CarbonImmutable::now(DB::table('organizations')->where('id',$org)->value('timezone'))->toDateString();
            return !DB::table('compliance_records')->where('organization_id',$org)->where('obligation_id',$obligation->id)->where('state','verified')->whereNull('suspended_at')
                ->where('valid_from','>',$date)->where('valid_from','<=',CarbonImmutable::parse($status['expires_on'])->addDay()->toDateString())->where('expires_on','>',$status['expires_on'])->exists();
        }
        if ($alert->kind==='maintenance.threshold_crossed') {
            $cycle=DB::table('maintenance_service_cycles as c')->join('vehicle_maintenance_plans as a',fn($j)=>$j->on('a.id','=','c.assignment_id')->on('a.organization_id','=','c.organization_id'))
                ->where('c.organization_id',$org)->where('c.id',$alert->episode_key)->where('a.vehicle_id',$vehicle->id)->where('a.status','active')->whereNull('c.ended_at')->first(['c.*']);
            if (!$cycle) return false;
            $status=app(\App\Services\Maintenance\MaintenanceStatus::class)->cycle($org,$vehicle->id,$cycle,CarbonImmutable::now('UTC'));
            foreach (json_decode($cycle->thresholds_snapshot,true) as $threshold) if ($threshold['key']===$alert->threshold_key) {
                if (!in_array('email',$threshold['channels'] ?? [],true)) return false;
                $tests=[];
                foreach (['remaining_km','remaining_days'] as $metric) if (isset($threshold[$metric.'_lte'])) $tests[]=$status[$metric]!==null && $status[$metric]<=$threshold[$metric.'_lte'];
                return $tests!==[] && (($threshold['match'] ?? 'any')==='all' ? !in_array(false,$tests,true) : in_array(true,$tests,true));
            }
            return false;
        }
        return false;
    }
}
