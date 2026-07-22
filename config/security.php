<?php

return [

    'headers' => [
        'enabled' => (bool) env('SECURITY_HEADERS_ENABLED', true),
        'hsts_enabled' => (bool) env('SECURITY_HSTS_ENABLED', false),
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'permissions_policy' => env('SECURITY_PERMISSIONS_POLICY', 'camera=(), microphone=(), geolocation=()'),
    ],

];
