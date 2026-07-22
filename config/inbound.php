<?php

return [
    'timestamp_skew_seconds' => (int) env('INBOUND_WEBHOOK_TIMESTAMP_SKEW_SECONDS', 300),
    'max_body_bytes' => (int) env('INBOUND_WEBHOOK_MAX_BODY_BYTES', 10485760),
    'rate_limit_per_minute' => (int) env('INBOUND_WEBHOOK_RATE_LIMIT_PER_MINUTE', 60),
    'providers' => [
        'generic' => ['secret' => env('INBOUND_GENERIC_WEBHOOK_SECRET')],
    ],
];
