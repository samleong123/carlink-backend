<?php

return [
    'hmac_key' => env('NFC_HMAC_KEY'),
    'hmac_key_version' => env('NFC_HMAC_KEY_VERSION', '1'),
    'shift_expiry_hours' => (int) env('NFC_SHIFT_EXPIRY_HOURS', 16),
    'debounce_seconds' => (int) env('NFC_AUTH_DEBOUNCE_SECONDS', 10),
];
