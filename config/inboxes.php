<?php

return [
    'expiration' => [
        'enabled' => (bool) env('INBOX_EXPIRATION_SCHEDULER_ENABLED', false),
        'batch_size' => (int) env('INBOX_EXPIRATION_BATCH_SIZE', 100),
    ],
    'max_lifetime_hours' => (int) env('INBOX_MAX_LIFETIME_HOURS', 720),
    'renewal' => [
        'enabled' => (bool) env('INBOX_RENEWAL_ENABLED', false),
        'max_extension_hours' => (int) env('INBOX_RENEWAL_MAX_EXTENSION_HOURS', 168),
    ],
];
