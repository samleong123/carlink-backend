<?php
return [
    'enforce_subscriptions' => env('SAAS_ENFORCE_SUBSCRIPTIONS', true),
    'managed_devices' => env('SAAS_MANAGED_DEVICES', true),
    'device_config_key' => env('DEVICE_CONFIG_SIGNING_KEY'),
    'broker_url' => env('DEVICE_MQTT_URL'),
    'diagnostic_fresh_seconds' => 5,
];
