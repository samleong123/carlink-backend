<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Message\AMQPMessage;

/** At-least-once delivery: downstream clients deduplicate stable message/event IDs. */
class PublishDeviceEventOutbox extends Command
{
    protected $signature = 'device-events:publish {--limit=20}';
    protected $description = 'Publish durable device results using AMQP publisher confirms';

    public function handle(): int
    {
        $s = config('broker');
        if (!$s['verified_topic_acls']) { $this->error('Publisher disabled: broker ACLs must be independently verified.'); return self::FAILURE; }
        foreach (['host', 'vhost', 'publisher_user', 'publisher_password', 'ca_file', 'exchange'] as $field) if (empty($s[$field])) { $this->error('Publisher disabled: missing broker '.$field.'.'); return self::FAILURE; }
        if (!is_readable($s['ca_file'])) { $this->error('Publisher disabled: broker CA is unavailable.'); return self::FAILURE; }
        $limit = max(1, min(100, (int) $this->option('limit')));
        $connection = new AMQPSSLConnection($s['host'], $s['port'], $s['publisher_user'], $s['publisher_password'], $s['vhost'], ['cafile'=>$s['ca_file'], 'verify_peer'=>true, 'verify_peer_name'=>true], ['connection_timeout'=>5, 'read_write_timeout'=>130, 'heartbeat'=>30, 'keepalive'=>true]);
        $channel = $connection->channel(); $channel->confirm_select();
        $returned = false; $nacked = false;
        $channel->set_return_listener(function () use (&$returned) { $returned = true; });
        $channel->set_nack_handler(function () use (&$nacked) { $nacked = true; });
        try {
            for ($i=0; $i<$limit; $i++) {
                $row = $this->claim();
                if (!$row) break;
                try {
                    $returned = false; $nacked = false;
                    $properties = ['delivery_mode'=>2, 'content_type'=>'application/json', 'message_id'=>$row->id];
                    if ($row->expires_at) {
                        $ttl = (int) floor((CarbonImmutable::parse($row->expires_at)->timestamp - now()->timestamp) * 1000);
                        if ($ttl <= 0) { $this->finish($row, false, true); continue; }
                        $properties['expiration'] = (string) $ttl;
                    }
                    $channel->basic_publish(new AMQPMessage($row->payload, $properties), $s['exchange'], $row->routing_key, true);
                    $channel->wait_for_pending_acks_returns(10);
                    if ($returned || $nacked) throw new \RuntimeException('Broker returned or rejected publication.');
                    $this->finish($row, true, false);
                } catch (\Throwable $e) {
                    $this->finish($row, false, false);
                    $this->error('Broker publication failed; retry is required.');
                    return self::FAILURE;
                }
            }
        } finally { $channel->close(); $connection->close(); }
        return self::SUCCESS;
    }

    private function claim(): ?object
    {
        return DB::transaction(function () {
            $row = DB::table('device_event_outbox')->whereNull('published_at')->whereNull('cancelled_at')->where('available_at', '<=', now())->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<=', now()))->orderBy('created_at')->lockForUpdate()->first();
            if (!$row) return null;
            $row->lease_token = (string) Str::uuid();
            DB::table('device_event_outbox')->where('id', $row->id)->update(['lease_token'=>$row->lease_token, 'locked_until'=>now()->addMinute(), 'attempts'=>DB::raw('attempts + 1')]);
            return $row;
        });
    }

    private function finish(object $claimed, bool $published, bool $expired): void
    {
        DB::transaction(function () use ($claimed, $published, $expired) {
            $current = DB::table('device_event_outbox')->where('id', $claimed->id)->where('lease_token', $claimed->lease_token)->lockForUpdate()->first();
            if (!$current) return;
            $updates = ['lease_token'=>null, 'locked_until'=>null];
            if ($expired) $updates['cancelled_at'] = now();
            elseif ($published && $current->generation === $claimed->generation) $updates['published_at'] = now();
            else {
                $delay = $published ? 0 : min(300, 2 ** min(8, (int) $current->attempts)) + random_int(0, 3);
                $updates['available_at'] = now()->addSeconds($delay);
            }
            DB::table('device_event_outbox')->where('id', $claimed->id)->update($updates);
        });
    }
}
