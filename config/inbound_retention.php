<?php

return [
    'email_days' => ((int) env('INBOUND_EMAIL_RETENTION_DAYS', 30) >= 1 && (int) env('INBOUND_EMAIL_RETENTION_DAYS', 30) <= 365) ? (int) env('INBOUND_EMAIL_RETENTION_DAYS', 30) : 0,
    'body_days' => ((int) env('INBOUND_BODY_RETENTION_DAYS', 30) >= 1 && (int) env('INBOUND_BODY_RETENTION_DAYS', 30) <= 365) ? (int) env('INBOUND_BODY_RETENTION_DAYS', 30) : 0,
    'attachment' => [
        'clean_days' => ((int) env('INBOUND_CLEAN_ATTACHMENT_RETENTION_DAYS', 30) >= 1 && (int) env('INBOUND_CLEAN_ATTACHMENT_RETENTION_DAYS', 30) <= 365) ? (int) env('INBOUND_CLEAN_ATTACHMENT_RETENTION_DAYS', 30) : 0,
        'infected_days' => ((int) env('INBOUND_INFECTED_ATTACHMENT_RETENTION_DAYS', 90) >= 1 && (int) env('INBOUND_INFECTED_ATTACHMENT_RETENTION_DAYS', 90) <= 730) ? (int) env('INBOUND_INFECTED_ATTACHMENT_RETENTION_DAYS', 90) : 0,
        'pending_days' => ((int) env('INBOUND_PENDING_ATTACHMENT_RETENTION_DAYS', 7) >= 1 && (int) env('INBOUND_PENDING_ATTACHMENT_RETENTION_DAYS', 7) <= 30) ? (int) env('INBOUND_PENDING_ATTACHMENT_RETENTION_DAYS', 7) : 0,
        'failed_days' => ((int) env('INBOUND_FAILED_ATTACHMENT_RETENTION_DAYS', 30) >= 1 && (int) env('INBOUND_FAILED_ATTACHMENT_RETENTION_DAYS', 30) <= 365) ? (int) env('INBOUND_FAILED_ATTACHMENT_RETENTION_DAYS', 30) : 0,
    ],
    'event_days' => ((int) env('INBOUND_EVENT_RETENTION_DAYS', 30) >= 1 && (int) env('INBOUND_EVENT_RETENTION_DAYS', 30) <= 365) ? (int) env('INBOUND_EVENT_RETENTION_DAYS', 30) : 0,
    'processing_log_days' => ((int) env('INBOUND_PROCESSING_LOG_RETENTION_DAYS', 30) >= 1 && (int) env('INBOUND_PROCESSING_LOG_RETENTION_DAYS', 30) <= 365) ? (int) env('INBOUND_PROCESSING_LOG_RETENTION_DAYS', 30) : 0,
    'failure_days' => ((int) env('INBOUND_FAILURE_RETENTION_DAYS', 90) >= 1 && (int) env('INBOUND_FAILURE_RETENTION_DAYS', 90) <= 730) ? (int) env('INBOUND_FAILURE_RETENTION_DAYS', 90) : 0,
    'batch_size' => max(1, min(1000, (int) env('INBOUND_RETENTION_BATCH_SIZE', 500))),
    'schedule' => env('INBOUND_RETENTION_SCHEDULE', 'daily'),
    'dry_run_default' => true,
    'legal_hold_required' => true,
    'inbound_hold_supported' => true,
    'cleanup_enabled' => filter_var(env('INBOUND_RETENTION_CLEANUP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];
