<?php
return [
    'key' => env('GEMINI_API_KEY'),
    'model' => env('DTC_AI_MODEL', 'gemini-3.5-flash-lite'),
    'audit_model' => env('AUDIT_AI_MODEL', 'gemini-3.5-flash-lite'),
    'timeout' => (int) env('DTC_AI_TIMEOUT', 90),
];
