<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master enable switch
    |--------------------------------------------------------------------------
    |
    | When false, referral tracking, attribution, and commission accrual are
    | fully disabled across the platform. Existing data is preserved.
    |
    */
    'enabled' => filter_var(env('AFFILIATE_ENABLED', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Registration mode
    |--------------------------------------------------------------------------
    |
    | disabled: no new affiliate applications are accepted.
    | manual_approval: applications are created as `pending` and require an
    | admin to approve them before they can earn commissions.
    | automatic: applications are activated immediately.
    |
    */
    'registration_mode' => env('AFFILIATE_REGISTRATION_MODE', 'manual_approval'),

    /*
    |--------------------------------------------------------------------------
    | Attribution model
    |--------------------------------------------------------------------------
    |
    | first_click: the earliest active attribution for a visitor wins.
    | last_click: the most recent click overwrites prior attribution.
    |
    */
    'attribution_model' => env('AFFILIATE_ATTRIBUTION_MODEL', 'last_click'),

    /*
    |--------------------------------------------------------------------------
    | Cookie settings
    |--------------------------------------------------------------------------
    */
    'cookie' => [
        'name' => env('AFFILIATE_COOKIE_NAME', 'temail_aff'),
        'days' => (int) env('AFFILIATE_COOKIE_DAYS', 30),
        'secure' => filter_var(env('AFFILIATE_COOKIE_SECURE', true), FILTER_VALIDATE_BOOL),
        'http_only' => filter_var(env('AFFILIATE_COOKIE_HTTP_ONLY', true), FILTER_VALIDATE_BOOL),
        'same_site' => env('AFFILIATE_COOKIE_SAME_SITE', 'lax'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Commission accrual
    |--------------------------------------------------------------------------
    |
    | hold_days: number of days a commission stays `pending`/`held` before it
    | becomes `available` for withdrawal (chargeback / refund protection).
    |
    | base: the order amount basis used to compute percentage commissions.
    | One of: subtotal, subtotal_after_discount, total.
    |
    */
    'commission_hold_days' => (int) env('AFFILIATE_COMMISSION_HOLD_DAYS', 14),
    'commission_base' => env('AFFILIATE_COMMISSION_BASE', 'subtotal_after_discount'),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    */
    'default_currency' => env('AFFILIATE_DEFAULT_CURRENCY', 'USD'),

    /** @var list<string> */
    'supported_currencies' => array_values(array_filter(array_map(
        static fn (string $currency): string => strtoupper(trim($currency)),
        explode(',', (string) env('AFFILIATE_SUPPORTED_CURRENCIES', 'USD')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Withdrawals
    |--------------------------------------------------------------------------
    */
    'min_withdrawal_minor' => (int) env('AFFILIATE_MIN_WITHDRAWAL_MINOR', 5000),

    /*
    |--------------------------------------------------------------------------
    | Payout methods offered to affiliates
    |--------------------------------------------------------------------------
    */
    'payout_methods' => [
        'bank_transfer',
        'paypal',
        'wise',
        'crypto_usdt_trc20',
        'manual_other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Same-actor payout restriction
    |--------------------------------------------------------------------------
    |
    | When true, an admin user cannot review/approve/pay a withdrawal that
    | they themselves requested, reviewed, or approved (separation of duties).
    |
    */
    'same_actor_payout_restriction' => filter_var(
        env('AFFILIATE_SAME_ACTOR_PAYOUT_RESTRICTION', false),
        FILTER_VALIDATE_BOOL,
    ),

    /*
    |--------------------------------------------------------------------------
    | Hashing key (visitor tokens, IPs, user agents)
    |--------------------------------------------------------------------------
    |
    | Falls back to app.key when not explicitly configured so hashed
    | identifiers rotate along with the application key.
    |
    */
    'hash_key' => env('AFFILIATE_HASH_KEY', env('APP_KEY', '')),

    /*
    |--------------------------------------------------------------------------
    | Order types eligible for commission generation
    |--------------------------------------------------------------------------
    */
    'eligible_order_types' => [
        'initial_purchase' => true,
        'renewal' => false,
        'recovery' => false,
        'upgrade' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fraud detection thresholds
    |--------------------------------------------------------------------------
    */
    'fraud' => [
        'fast_conversion_seconds' => (int) env('AFFILIATE_FRAUD_FAST_CONVERSION_SECONDS', 300),
        'excessive_clicks_per_hour' => (int) env('AFFILIATE_FRAUD_EXCESSIVE_CLICKS_PER_HOUR', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Referral redirect allow-list
    |--------------------------------------------------------------------------
    |
    | Only internal (relative) paths are allowed as referral landing
    | redirects to prevent open-redirect abuse via referral links.
    |
    */
    'redirect' => [
        'allowed_paths' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AFFILIATE_REDIRECT_ALLOWED_PATHS', '/')),
        ))),
        'default_path' => env('AFFILIATE_REDIRECT_DEFAULT_PATH', '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler toggles
    |--------------------------------------------------------------------------
    |
    | maturity_scheduler: promotes held commission entries to `available`
    | once their hold period elapses.
    | attribution_prune: deletes/archives old expired or converted
    | attributions past the retention window.
    | attribution_expire: marks stale `active` attributions as `expired`.
    |
    */
    'scheduler' => [
        'maturity_enabled' => filter_var(env('AFFILIATE_MATURITY_SCHEDULER_ENABLED', false), FILTER_VALIDATE_BOOL),
        'attribution_prune_enabled' => filter_var(env('AFFILIATE_ATTRIBUTION_PRUNE_ENABLED', false), FILTER_VALIDATE_BOOL),
        'attribution_expire_enabled' => filter_var(env('AFFILIATE_ATTRIBUTION_EXPIRE_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    */
    'attribution_retention_days' => (int) env('AFFILIATE_ATTRIBUTION_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Batch sizes for scheduled jobs
    |--------------------------------------------------------------------------
    */
    'batch_sizes' => [
        'maturity' => (int) env('AFFILIATE_MATURITY_BATCH_SIZE', 200),
        'attribution_prune' => (int) env('AFFILIATE_ATTRIBUTION_PRUNE_BATCH_SIZE', 500),
        'attribution_expire' => (int) env('AFFILIATE_ATTRIBUTION_EXPIRE_BATCH_SIZE', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics cache
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'cache_key_prefix' => 'affiliate.metrics.',
        'cache_ttl_seconds' => (int) env('AFFILIATE_METRICS_CACHE_TTL_SECONDS', 300),
    ],

];
