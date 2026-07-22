<?php

return [
    'api_request_logs_days' => (int) env('API_REQUEST_LOG_RETENTION_DAYS', 30),
    'audit_logs_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 2555),
    'cleanup_schedule' => env('LOG_RETENTION_CLEANUP_SCHEDULE', 'daily'),
    'batch_size' => (int) env('LOG_RETENTION_BATCH_SIZE', 500),
    'audit_hold_supported' => true,
    'audit_log_retention_cleanup_enabled' => filter_var(
        env('AUDIT_LOG_RETENTION_CLEANUP_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN,
    ),
    // Contract only: Prompt 348 will validate and consume these values.
    'api_request_logs' => [
        'days' => (int) env('API_REQUEST_LOG_RETENTION_DAYS', 30),
        'minimum_days' => 7,
        'maximum_days' => 365,
    ],
    'audit_logs' => [
        'days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 2555),
        'minimum_days' => 365,
        'maximum_days' => 3650,
    ],
    'cleanup' => [
        'schedule' => env('LOG_RETENTION_CLEANUP_SCHEDULE', 'daily'),
        'dry_run_default' => true,
    ],
];
