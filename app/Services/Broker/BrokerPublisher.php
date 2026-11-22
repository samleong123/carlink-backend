<?php

namespace App\Services\Broker;

interface BrokerPublisher
{
    /** Return only after publisher confirm AND absence of an unroutable return. */
    public function publish(string $routingKey, string $payload, string $messageId, int $ttlMs): void;
}
