<?php

namespace App\Services\Broker;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BrokerOutbox
{
    public function ingestAck(object $inbox, string $status): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('ACK must commit with durable acceptance.');
        $key = 'telemetry:'.$inbox->id;
        $row = DB::table('broker_outbox')->where('deduplication_key', $key)->lockForUpdate()->first();
        $id = $row->id ?? (string) Str::uuid();
        $payload = ['schema_version'=>'1.0','message_type'=>'ingest_ack','device_id'=>$inbox->device_id,'acknowledged_at'=>now('UTC')->format('Y-m-d\TH:i:s.v\Z'),'results'=>[['event_id'=>$inbox->event_id,'status'=>$status]]];
        $values = ['organization_id'=>$inbox->organization_id,'device_id'=>$inbox->device_id,'routing_key'=>"fleet.v1.{$inbox->organization_id}.{$inbox->device_id}.down.ingest_ack", 'payload'=>json_encode($payload, JSON_THROW_ON_ERROR), 'generation'=>($row->generation ?? 0)+1,'available_at'=>now(),'expires_at'=>now()->addDays(7),'published_at'=>null,'cancelled_at'=>null,'updated_at'=>now()];
        if ($row) DB::table('broker_outbox')->where('id', $id)->update($values);
        else DB::table('broker_outbox')->insert(['id'=>$id,'deduplication_key'=>$key,'created_at'=>now()] + $values);
    }

    public function publishOne(BrokerPublisher $publisher): bool
    {
        $row = DB::transaction(function () {
            $row = DB::table('broker_outbox')->whereNull('published_at')->whereNull('cancelled_at')->where('available_at','<=',now())
                ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until','<=',now()))->orderBy('created_at')->lockForUpdate()->first();
            if (!$row) return null;
            $row->lease_token = (string) Str::uuid();
            DB::table('broker_outbox')->where('id',$row->id)->update(['lease_token'=>$row->lease_token,'locked_until'=>now()->addMinutes(3),'attempts'=>DB::raw('attempts + 1')]);
            return $row;
        });
        if (!$row) return false;
        $ttl = (int) floor((CarbonImmutable::parse($row->expires_at)->timestamp - now()->timestamp)*1000);
        try {
            if ($ttl > 0) $publisher->publish($row->routing_key, $row->payload, $row->id, $ttl);
            DB::transaction(function () use ($row, $ttl) {
                $current = DB::table('broker_outbox')->where('id',$row->id)->where('lease_token',$row->lease_token)->lockForUpdate()->first();
                if (!$current) return;
                $updates = ['lease_token'=>null,'locked_until'=>null];
                if ($current->generation === $row->generation) $updates[$ttl > 0 ? 'published_at' : 'cancelled_at'] = now();
                DB::table('broker_outbox')->where('id',$row->id)->update($updates);
            });
        } catch (\Throwable $e) {
            DB::table('broker_outbox')->where('id',$row->id)->where('lease_token',$row->lease_token)->update(['lease_token'=>null,'locked_until'=>null,'available_at'=>now()->addSeconds(min(300, 2 ** min(8, $row->attempts+1)))]);
            throw $e;
        }
        return true;
    }
}
