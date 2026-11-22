<?php

return [
    // This is an operator attestation, not a substitute for broker ACL tests.
    'verified_topic_acls' => env('BROKER_VERIFIED_TOPIC_ACLS', false),
    'host' => env('RABBITMQ_HOST', ''),
    'port' => (int) env('RABBITMQ_PORT', 5671),
    'vhost' => env('RABBITMQ_VHOST', ''),
    'user' => env('RABBITMQ_USER', ''),
    'password' => env('RABBITMQ_PASSWORD', ''),
    'ca_file' => env('RABBITMQ_TLS_CA_FILE', ''),
    'queue' => env('RABBITMQ_TELEMETRY_QUEUE', ''),
    'events_queue' => env('RABBITMQ_EVENTS_QUEUE', ''),
    'publisher_user' => env('RABBITMQ_PUBLISHER_USER', ''),
    'publisher_password' => env('RABBITMQ_PUBLISHER_PASSWORD', ''),
    'exchange' => env('RABBITMQ_MQTT_EXCHANGE', 'amq.topic'),
    'management' => [
        'url' => env('RABBITMQ_MANAGEMENT_URL', ''),
        'user' => env('RABBITMQ_MANAGEMENT_USER', ''),
        'password' => env('RABBITMQ_MANAGEMENT_PASSWORD', ''),
        'timeout' => (int) env('RABBITMQ_MANAGEMENT_TIMEOUT', 5),
    ],
];
