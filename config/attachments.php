<?php

return [
    // Deliberately disabled until an approved production scanner is configured.
    'scanner_backend' => env('ATTACHMENT_SCANNER_BACKEND', 'disabled'),
    'clamav' => [
        'host' => env('ATTACHMENT_CLAMAV_HOST', '127.0.0.1'),
        'port' => (int) env('ATTACHMENT_CLAMAV_PORT', 3310),
        'timeout_seconds' => (int) env('ATTACHMENT_SCAN_TIMEOUT_SECONDS', 30),
    ],
    'external' => [
        'endpoint' => env('ATTACHMENT_SCANNER_ENDPOINT'),
        'timeout_seconds' => (int) env('ATTACHMENT_SCAN_TIMEOUT_SECONDS', 30),
    ],
    'max_bytes' => (int) env('ATTACHMENT_SCAN_MAX_BYTES', 26214400),
    'max_count' => (int) env('ATTACHMENT_MAX_COUNT', 20),
    'max_total_bytes' => (int) env('ATTACHMENT_MAX_TOTAL_BYTES', 52428800),
    'retry' => [
        'max_attempts' => (int) env('ATTACHMENT_SCAN_MAX_ATTEMPTS', 3),
        'backoff_seconds' => [60, 300, 900],
    ],
];
