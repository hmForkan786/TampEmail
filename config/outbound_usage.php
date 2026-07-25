<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Outbound subscription usage metering
|--------------------------------------------------------------------------
|
| Governs OutboundUsageService: how outbound send/reply/forward usage is
| reserved, committed, and released against plan entitlements. Fully
| separate from OutboundRateLimiter (abuse/throttling) — this file never
| configures abuse thresholds.
|
| Backward-compatibility policy (see docs/OUTBOUND_USAGE_ACCOUNTING.md):
| when a plan does not have a metered feature
| (outbound_messages_per_period / outbound_recipients_per_period /
| outbound_attachment_bytes_per_period) attached, that dimension is
| treated as UNLIMITED rather than falling back to the free_defaults
| below. free_defaults exist only for optional demo/plan seeding
| reference and are never applied automatically.
|
*/

return [

    // Master switch. When false, reserve()/commit()/release() are no-ops
    // and no reservation rows are created — outbound send/reply/forward
    // behaves exactly as before this prompt.
    'metering_enabled' => filter_var(env('OUTBOUND_USAGE_METERING_ENABLED', true), FILTER_VALIDATE_BOOL),

    // How long a `reserved` (not yet committed) reservation may live before
    // it is eligible for reconciliation review. Does not affect enforcement
    // directly; outstanding reservations always count toward allowance
    // regardless of age until committed/released.
    'reservation_ttl_seconds' => max(60, (int) env('OUTBOUND_USAGE_RESERVATION_TTL_SECONDS', 3600)),

    // Fallback reset period when a plan's feature_value omits
    // `reset_period`. Must be a valid App\Enums\ResetPeriod value.
    'default_reset_period' => (string) env('OUTBOUND_USAGE_DEFAULT_RESET_PERIOD', 'monthly'),

    'reconcile' => [
        'batch_size' => max(1, min(1000, (int) env('OUTBOUND_USAGE_RECONCILE_BATCH_SIZE', 200))),
        'dry_run_default' => true,
    ],

    // Reference-only defaults for optional demo/plan seeding. Never
    // attached to a plan automatically by FeatureSeeder.
    'free_defaults' => [
        'messages_per_period' => (int) env('OUTBOUND_USAGE_FREE_MESSAGES_PER_PERIOD', 200),
        'recipients_per_period' => (int) env('OUTBOUND_USAGE_FREE_RECIPIENTS_PER_PERIOD', 500),
        'attachment_bytes_per_period' => (int) env('OUTBOUND_USAGE_FREE_ATTACHMENT_BYTES_PER_PERIOD', 104857600),
        'reset_period' => (string) env('OUTBOUND_USAGE_FREE_RESET_PERIOD', 'monthly'),
    ],

    // Stable feature-key registry consumed by OutboundUsageService. Do not
    // rename without a migration for existing feature_plan rows.
    'feature_keys' => [
        'messages' => 'outbound_messages_per_period',
        'recipients' => 'outbound_recipients_per_period',
        'attachment_bytes' => 'outbound_attachment_bytes_per_period',
    ],

    // Documentation only: period boundaries are calendar-aligned (UTC) via
    // Carbon::startOf{Day,Week,Month,Year}() anchored on the application
    // timezone at the moment a usage row is first created for a period.
    // No separate per-user timezone conversion is performed.
    'timezone_note' => 'Usage periods are calendar-aligned in the application timezone (config(app.timezone)); no per-user timezone override is applied.',

];
