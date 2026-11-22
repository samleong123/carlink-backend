<?php

return [
    'url' => env('PAYMENT_GATEWAY_URL', 'https://gateway.samsam123.name.my/api/generateLink'),
    'token' => env('PAYMENT_GATEWAY_TOKEN'),
    'checkout_host' => env('PAYMENT_GATEWAY_CHECKOUT_HOST', 'gateway.samsam123.name.my'),
    'callback_base_url' => env('PAYMENT_GATEWAY_CALLBACK_BASE_URL', env('APP_URL')),
    'timeout_seconds' => (int) env('PAYMENT_GATEWAY_TIMEOUT', 10),
];
