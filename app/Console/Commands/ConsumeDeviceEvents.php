<?php

namespace App\Console\Commands;

use App\Services\DeviceEvents\DriverAuthenticationInbox;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumeDeviceEvents extends Command
{
    protected $signature = 'device-events:consume';
    protected $description = 'Consume authenticated driver events from a pre-provisioned broker queue';

    public function handle(DriverAuthenticationInbox $inbox): int
    {
        $settings = config('broker');
        if (!$settings['verified_topic_acls']) { $this->error('Consumer disabled: broker ACLs must be independently verified.'); return self::FAILURE; }
        foreach (['host', 'vhost', 'user', 'password', 'ca_file', 'events_queue'] as $field) {
            if (empty($settings[$field])) { $this->error('Consumer disabled: missing broker '.$field.'.'); return self::FAILURE; }
        }
        if (!is_readable($settings['ca_file']) || !is_string(config('nfc.hmac_key')) || strlen(config('nfc.hmac_key')) < 32) { $this->error('Consumer disabled: broker CA or NFC HMAC key is unavailable.'); return self::FAILURE; }
        $connection = new AMQPSSLConnection($settings['host'], $settings['port'], $settings['user'], $settings['password'], $settings['vhost'], ['cafile'=>$settings['ca_file'], 'verify_peer'=>true, 'verify_peer_name'=>true], ['connection_timeout'=>5, 'read_write_timeout'=>130, 'heartbeat'=>30, 'keepalive'=>true]);
        $channel = $connection->channel(); $channel->basic_qos(null, 25, null);
        $channel->basic_consume($settings['events_queue'], '', false, false, false, false, function (AMQPMessage $message) use ($inbox) {
            try {
                $result = $inbox->receive((string) $message->getRoutingKey(), $message->getBody());
                if (in_array($result, ['unsupported_message_type', 'unsupported_offline_auth'], true)) throw new \RuntimeException('Unsupported events topic message: preserve and stop consumer.');
                $message->ack();
            } catch (\Throwable $error) { $message->nack(true, false); throw $error; }
        });
        try { while ($channel->is_consuming()) $channel->wait(); }
        finally { $channel->close(); $connection->close(); }
        return self::SUCCESS;
    }
}
