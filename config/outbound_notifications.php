<?php

return [
    'mailer' => env('SYSTEM_NOTIFICATION_MAILER', env('MAIL_MAILER', 'log')),
    'from_address' => env('SYSTEM_NOTIFICATION_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
    'from_name' => env('SYSTEM_NOTIFICATION_FROM_NAME', env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel'))),
    'retention_days' => (int) env('OUTBOUND_NOTIFICATION_RETENTION_DAYS', 90),
    'usage_warning_percent' => (int) env('OUTBOUND_NOTIFICATION_USAGE_WARNING_PERCENT', 80),
    'events' => ['outbound.queued', 'outbound.sent', 'outbound.delivered', 'outbound.failed', 'outbound.cancelled', 'outbound.scheduled', 'outbound.schedule_deferred', 'outbound.schedule_failed', 'outbound.usage_warning', 'outbound.usage_exhausted'],
    'defaults' => ['outbound.queued' => ['in_app' => true, 'email' => false], 'outbound.sent' => ['in_app' => true, 'email' => false], 'outbound.delivered' => ['in_app' => true, 'email' => false], 'outbound.failed' => ['in_app' => true, 'email' => true], 'outbound.cancelled' => ['in_app' => true, 'email' => false], 'outbound.scheduled' => ['in_app' => true, 'email' => false], 'outbound.schedule_deferred' => ['in_app' => true, 'email' => false], 'outbound.schedule_failed' => ['in_app' => true, 'email' => true], 'outbound.usage_warning' => ['in_app' => true, 'email' => true], 'outbound.usage_exhausted' => ['in_app' => true, 'email' => true]],
];
