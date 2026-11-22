<?php

namespace App\Console\Commands;

use App\Services\Telemetry\TelemetryInbox;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumeTelemetry extends Command
{
    protected $signature = 'telemetry:consume';
    protected $description = 'Consume telemetry from a pre-provisioned, access-controlled AMQP queue';

    public function handle(TelemetryInbox $inbox): int
    {
        $settings = config('broker');
        if (!$settings['verified_topic_acls']) {
            $this->error('Consumer disabled: broker topic and queue ACLs must be independently verified.');
            return self::FAILURE;
        }
        foreach (['host', 'vhost', 'user', 'password', 'ca_file', 'queue'] as $field) {
            if (empty($settings[$field])) {
                $this->error('Consumer disabled: missing broker '.$field.'.');
                return self::FAILURE;
            }
        }
        if (!is_readable($settings['ca_file'])) {
            $this->error('Consumer disabled: broker CA file is not readable.');
            return self::FAILURE;
        }
        $connection = new AMQPSSLConnection($settings['host'], $settings['port'], $settings['user'], $settings['password'], $settings['vhost'], [
            'cafile' => $settings['ca_file'], 'verify_peer' => true, 'verify_peer_name' => true,
        ], ['connection_timeout' => 5, 'read_write_timeout' => 130, 'heartbeat' => 30, 'keepalive' => true]);
        $channel = $connection->channel();
        $channel->basic_qos(null, 25, null);
        // Queue and binding are provisioned externally; this command never broadens broker ACLs.
        $channel->basic_consume($settings['queue'], '', false, false, false, false, function (AMQPMessage $message) use ($inbox) {
            try {
                $inbox->receive((string) $message->getRoutingKey(), $message->getBody());
                // This confirms persistence to RabbitMQ only, not application-level acceptance by the device.
                $message->ack();
            } catch (\Throwable $error) {
                // Leave responsibility with the broker and stop for supervised retry/backoff.
                $message->nack(true, false);
                throw $error;
            }
        });
        try {
            while ($channel->is_consuming()) $channel->wait();
        } finally {
            $channel->close();
            $connection->close();
        }
        return self::SUCCESS;
    }
}
