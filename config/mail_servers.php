<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Health window
    |--------------------------------------------------------------------------
    |
    | last_health_check_at must be within this many minutes to count as fresh.
    | Sidecars / operators refresh the timestamp; the app does not SMTP-probe.
    |
    */

    'health_window_minutes' => max(1, (int) env('MAIL_SERVER_HEALTH_WINDOW_MINUTES', 10)),

    /*
    |--------------------------------------------------------------------------
    | Health scoring (deterministic, 0–100)
    |--------------------------------------------------------------------------
    */

    'scoring' => [
        'active_status_points' => (int) env('MAIL_SERVER_SCORE_ACTIVE_POINTS', 40),
        'fresh_check_points' => (int) env('MAIL_SERVER_SCORE_FRESH_POINTS', 40),
        'zero_failure_points' => (int) env('MAIL_SERVER_SCORE_ZERO_FAILURE_POINTS', 20),
        'failure_penalty_per_strike' => (int) env('MAIL_SERVER_SCORE_FAILURE_PENALTY', 10),
        'max_failure_strikes' => (int) env('MAIL_SERVER_SCORE_MAX_FAILURE_STRIKES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Selection / routing
    |--------------------------------------------------------------------------
    |
    | Eligible servers: operational_status=active, is_active=true, fresh health,
    | health_score >= min_health_score, under capacity.
    | Order: lowest utilization → highest health_score → highest priority → id ASC.
    |
    */

    'selection' => [
        'min_health_score' => (int) env('MAIL_SERVER_MIN_HEALTH_SCORE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Failover (assignment-time only)
    |--------------------------------------------------------------------------
    |
    | Selection walks the ordered candidate list. Bounded by candidate count;
    | no duplicate inbox rows are created (one assignment per create txn).
    |
    */

    'failover' => [
        'max_candidate_evaluations' => max(1, (int) env('MAIL_SERVER_FAILOVER_MAX_CANDIDATES', 50)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance / draining
    |--------------------------------------------------------------------------
    */

    'maintenance' => [
        'auto_complete_drain' => filter_var(env('MAIL_SERVER_AUTO_COMPLETE_DRAIN', true), FILTER_VALIDATE_BOOL),
        'drain_to_status' => env('MAIL_SERVER_DRAIN_TO_STATUS', 'maintenance'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler batching
    |--------------------------------------------------------------------------
    */

    'refresh_batch_size' => max(1, (int) env('MAIL_SERVER_REFRESH_BATCH_SIZE', 100)),

];
