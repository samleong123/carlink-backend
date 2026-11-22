<?php

namespace App\Services\Broker;

use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Message\AMQPMessage;

class AmqpPublisher implements BrokerPublisher
{
    public function publish(string $routingKey, string $payload, string $messageId, int $ttlMs): void
    {
        $s = config('broker');
        if (!$s['verified_topic_acls']) throw new \LogicException('Broker ACL attestation required.');
        foreach (['host','vhost','publisher_user','publisher_password','ca_file','exchange'] as $key) if (empty($s[$key])) throw new \LogicException('Broker publisher configuration incomplete.');
        if (!is_readable($s['ca_file']) || $ttlMs <= 0) throw new \LogicException('Broker CA or message expiry invalid.');
        $connection = new AMQPSSLConnection($s['host'], $s['port'], $s['publisher_user'], $s['publisher_password'], $s['vhost'], ['cafile'=>$s['ca_file'],'verify_peer'=>true,'verify_peer_name'=>true], ['connection_timeout'=>5,'read_write_timeout'=>130,'heartbeat'=>30,'keepalive'=>true]);
        $channel = $connection->channel();
        try {
            $channel->confirm_select(); $failed = false;
            $channel->set_return_listener(function () use (&$failed) { $failed = true; });
            $channel->set_nack_handler(function () use (&$failed) { $failed = true; });
            $channel->basic_publish(new AMQPMessage($payload, ['delivery_mode'=>2,'content_type'=>'application/json','message_id'=>$messageId,'expiration'=>(string)$ttlMs]), $s['exchange'], $routingKey, true);
            $channel->wait_for_pending_acks_returns(10);
            if ($failed) throw new \RuntimeException('Broker publication not confirmed/routable.');
        } finally { $channel->close(); $connection->close(); }
    }
}
