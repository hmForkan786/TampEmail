<?php

return [
    // Deliberately disabled until an approved production scanner is configured.
    'scanner_backend' => env('ATTACHMENT_SCANNER_BACKEND', 'disabled'),
    'clamav' => [
        'host' => env('ATTACHMENT_CLAMAV_HOST', '127.0.0.1'),
        'port' => (int) env('ATTACHMENT_CLAMAV_PORT', 3310),
        'socket' => env('ATTACHMENT_CLAMAV_SOCKET'),
        'connect_timeout_seconds' => (float) env('ATTACHMENT_CLAMAV_CONNECT_TIMEOUT_SECONDS', 5),
        'read_timeout_seconds' => (float) env('ATTACHMENT_CLAMAV_READ_TIMEOUT_SECONDS', 30),
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
        // Existing production contract defaults; override via ATTACHMENT_SCAN_BACKOFF_SECONDS.
        'backoff_seconds' => env('ATTACHMENT_SCAN_BACKOFF_SECONDS', '60,300,900'),
    ],
    'ops' => [
        'pending_backlog_threshold' => (int) env('ATTACHMENT_SCAN_PENDING_BACKLOG_THRESHOLD', 100),
        'oldest_pending_seconds_threshold' => (int) env('ATTACHMENT_SCAN_OLDEST_PENDING_SECONDS', 600),
        'failed_scans_last_hour_threshold' => (int) env('ATTACHMENT_SCAN_FAILED_LAST_HOUR_THRESHOLD', 5),
        'retry_exhausted_surge_threshold' => (int) env('ATTACHMENT_SCAN_RETRY_EXHAUSTED_SURGE', 10),
        'live_check_per_minute' => (int) env('ATTACHMENT_SCAN_LIVE_CHECK_PER_MINUTE', 1),
    ],
];
