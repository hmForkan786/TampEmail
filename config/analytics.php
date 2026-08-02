<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master enable switch
    |--------------------------------------------------------------------------
    |
    | When false, collectors and rollups no-op; dashboards still render empty
    | read models. Analytics never mutates Billing, Mail, or API responses.
    |
    */
    'enabled' => (bool) env('ANALYTICS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Retention (days)
    |--------------------------------------------------------------------------
    */
    'retention' => [
        'events_days' => (int) env('ANALYTICS_EVENTS_RETENTION_DAYS', 90),
        'rollups_days' => (int) env('ANALYTICS_ROLLUPS_RETENTION_DAYS', 730),
        'runs_days' => (int) env('ANALYTICS_RUNS_RETENTION_DAYS', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler toggles
    |--------------------------------------------------------------------------
    */
    'scheduler' => [
        'rollup_enabled' => (bool) env('ANALYTICS_SCHEDULER_ROLLUP', true),
        'prune_enabled' => (bool) env('ANALYTICS_SCHEDULER_PRUNE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rollup behaviour
    |--------------------------------------------------------------------------
    */
    'rollup' => [
        // How many past days to backfill when a day has no successful run.
        'backfill_days' => (int) env('ANALYTICS_ROLLUP_BACKFILL_DAYS', 7),
        // Active-user window for users.active (days with last_login / activity proxy).
        'active_user_days' => (int) env('ANALYTICS_ACTIVE_USER_DAYS', 30),
        // Cohort window for crude retention (registered N days ago, still active).
        'retention_cohort_days' => (int) env('ANALYTICS_RETENTION_COHORT_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Analytics work is delayed-tolerant and must never share mail ingestion
    | queues. Failures here must not block product workflows.
    |
    */
    'queue' => env('ANALYTICS_QUEUE', 'analytics'),

    /*
    |--------------------------------------------------------------------------
    | PII deny-list (never persist these dimension keys)
    |--------------------------------------------------------------------------
    */
    'pii_deny_keys' => [
        'email',
        'password',
        'subject',
        'body',
        'html_body',
        'text_body',
        'ip',
        'ip_address',
        'user_agent',
        'phone',
        'name',
        'display_name',
        'full_address',
        'local_part',
        'token',
        'secret',
        'api_key',
        'authorization',
        'payout_details',
        'payout_details_encrypted',
    ],

];
