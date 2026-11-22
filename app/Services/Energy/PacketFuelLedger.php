<?php

namespace App\Services\Energy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Independent packet-rate integration. No trusting edge cumulative totals, bridging gaps or implying measured fuel. */
class PacketFuelLedger
{
    public function connect(string $sampleId): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Energy projection requires a transaction.');
        $s=DB::table('telemetry_samples')->where('id',$sampleId)->first();
        $query=fn()=>DB::table('telemetry_samples')->where('organization_id',$s->organization_id)->where('vehicle_id',$s->vehicle_id);
        $a=$query()->where('occurred_at','<',$s->occurred_at)->orderByDesc('occurred_at')->first();
        $b=$query()->where('occurred_at','>',$s->occurred_at)->orderBy('occurred_at')->first();
        if ($query()->where('occurred_at',$s->occurred_at)->where('id','<>',$s->id)->exists()) throw new \DomainException('Ambiguous coincident fuel snapshots.');
        $maxGap=app(\App\Services\Entitlements::class)->maxCompatibleGapSeconds($s->organization_id);
        if ($a && $b) DB::table('vehicle_energy_segments')->where('start_sample_id',$a->id)->where('end_sample_id',$b->id)->whereNull('superseded_at')->update(['superseded_at'=>now()]);
        if ($a) $this->segment($a,$s,$maxGap);
        if ($b) $this->segment($s,$b,$maxGap);
    }

    private function segment(object $a, object $b, int $maxGap): void
    {
        $seconds=CarbonImmutable::parse($a->occurred_at)->diffInSeconds(CarbonImmutable::parse($b->occurred_at),false);
        $valid=$seconds > 0 && $seconds <= $maxGap && $a->installation_id === $b->installation_id && $a->source_format === 'pi_snapshot_v1'
            && $b->source_format === 'pi_snapshot_v1' && $a->fuel_rate_lph !== null && $b->fuel_rate_lph !== null && $a->fuel_rate_source === $b->fuel_rate_source;
        $litres=$valid ? (((float)$a->fuel_rate_lph+(float)$b->fuel_rate_lph)*$seconds/7200) : null;
        DB::table('vehicle_energy_segments')->insert(['id'=>(string)Str::uuid(),'organization_id'=>$a->organization_id,'vehicle_id'=>$a->vehicle_id,'start_sample_id'=>$a->id,'end_sample_id'=>$b->id,
            'from_at'=>$a->occurred_at,'to_at'=>$b->occurred_at,'litres'=>$litres,'source'=>$valid ? $a->fuel_rate_source : 'unknown','quality'=>$valid ? 'packet_snapshot_estimate' : 'source_gap_or_unavailable','created_at'=>now()]);
    }
}
