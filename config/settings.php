<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Profile allowlists
    |--------------------------------------------------------------------------
    */
    'locales' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SETTINGS_LOCALES', 'en,bn'))
    ))),

    'timezones' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'SETTINGS_TIMEZONES',
            'UTC,Asia/Dhaka,America/New_York,Europe/London,Asia/Tokyo,Asia/Kolkata'
        ))
    ))),

    'avatar' => [
        'enabled' => (bool) env('SETTINGS_AVATAR_ENABLED', false),
        'disk' => env('SETTINGS_AVATAR_DISK', 'local'),
        'visibility' => env('SETTINGS_AVATAR_VISIBILITY', 'private'),
        'max_kb' => (int) env('SETTINGS_AVATAR_MAX_KB', 512),
        'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password change
    |--------------------------------------------------------------------------
    */
    'password_change' => [
        'revoke_other_sessions' => (bool) env('PASSWORD_CHANGE_REVOKE_OTHER_SESSIONS', true),
        'revoke_api_keys' => (bool) env('PASSWORD_CHANGE_REVOKE_API_KEYS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification preferences
    |--------------------------------------------------------------------------
    */
    'notifications' => [
        'channels' => ['in_app', 'email'],
        'categories' => [
            'security' => [
                'critical' => true,
                'defaults' => ['in_app' => true, 'email' => true],
            ],
            'billing' => [
                'critical' => false,
                'transactional' => true,
                'defaults' => ['in_app' => true, 'email' => true],
            ],
            'inbox' => [
                'critical' => false,
                'defaults' => ['in_app' => true, 'email' => false],
            ],
            'outbound' => [
                'critical' => false,
                'defaults' => ['in_app' => true, 'email' => true],
            ],
            'affiliate' => [
                'critical' => false,
                'defaults' => ['in_app' => true, 'email' => true],
            ],
            'product_updates' => [
                'critical' => false,
                'defaults' => ['in_app' => true, 'email' => false],
            ],
            'marketing' => [
                'critical' => false,
                'marketing' => true,
                'defaults' => ['in_app' => false, 'email' => false],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Marketing consent
    |--------------------------------------------------------------------------
    */
    'marketing' => [
        'policy_version' => env('SETTINGS_MARKETING_POLICY_VERSION', '2026-08-01'),
        'default_source' => 'settings',
    ],

    /*
    |--------------------------------------------------------------------------
    | API keys (settings UI policy)
    |--------------------------------------------------------------------------
    */
    'api_keys' => [
        'require_password' => (bool) env('SETTINGS_API_KEY_REQUIRE_PASSWORD', true),
        'default_scopes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SETTINGS_API_KEY_DEFAULT_SCOPES', 'inboxes:read'))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing preferences / privacy
    |--------------------------------------------------------------------------
    */
    'billing_preferences' => [
        'enabled' => true,
    ],

    'privacy' => [
        'policy_url' => env('SETTINGS_PRIVACY_POLICY_URL', '/privacy'),
        'policy_version' => env('SETTINGS_PRIVACY_POLICY_VERSION', '2026-08-01'),
        'login_history_retention_days' => (int) env('IDENTITY_LOGIN_HISTORY_DAYS', 90),
        'cookie_preferences_documented' => true,
        'export' => [
            'enabled' => (bool) env('SETTINGS_PRIVACY_EXPORT_ENABLED', true),
            'disk' => env('SETTINGS_PRIVACY_EXPORT_DISK', 'local'),
            'directory' => env('SETTINGS_PRIVACY_EXPORT_DIRECTORY', 'private/settings/exports'),
            'ttl_hours' => (int) env('SETTINGS_PRIVACY_EXPORT_TTL_HOURS', 48),
            'rate_limit_hours' => (int) env('SETTINGS_PRIVACY_EXPORT_RATE_LIMIT_HOURS', 24),
            'include_datasets' => [
                'profile',
                'preferences',
                'notification_preferences',
                'billing_preferences',
                'api_key_metadata',
                'billing_history',
                'login_history_metadata',
                'affiliate_records',
            ],
            'deferred_datasets' => [
                'inbox_content',
                'email_bodies',
                'full_audit_trail',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Account closure confirmation
    |--------------------------------------------------------------------------
    */
    'account_closure' => [
        'confirmation_phrase' => env('SETTINGS_ACCOUNT_CLOSURE_PHRASE', 'DELETE MY ACCOUNT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'profile_per_minute' => (int) env('RATE_LIMIT_SETTINGS_PROFILE_PER_MINUTE', 10),
        'password_per_minute' => (int) env('RATE_LIMIT_SETTINGS_PASSWORD_PER_MINUTE', 5),
        'email_change_per_minute' => (int) env('RATE_LIMIT_SETTINGS_EMAIL_CHANGE_PER_MINUTE', 3),
        'verification_resend_per_minute' => (int) env('RATE_LIMIT_SETTINGS_VERIFICATION_RESEND_PER_MINUTE', 3),
        'session_revoke_per_minute' => (int) env('RATE_LIMIT_SETTINGS_SESSION_REVOKE_PER_MINUTE', 10),
        'api_key_per_minute' => (int) env('RATE_LIMIT_SETTINGS_API_KEY_PER_MINUTE', 10),
        'export_per_hour' => (int) env('RATE_LIMIT_SETTINGS_EXPORT_PER_HOUR', 3),
        'account_close_per_hour' => (int) env('RATE_LIMIT_SETTINGS_ACCOUNT_CLOSE_PER_HOUR', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler / prune
    |--------------------------------------------------------------------------
    */
    'prune' => [
        'enabled' => (bool) env('SETTINGS_PRUNE_ENABLED', false),
        'batch_size' => (int) env('SETTINGS_PRUNE_BATCH_SIZE', 100),
        'expire_stale_email_changes_hours' => (int) env('SETTINGS_EXPIRE_STALE_EMAIL_CHANGES_HOURS', 72),
    ],

    'scheduler' => [
        'prune_expired_exports' => (bool) env('SETTINGS_SCHEDULER_PRUNE_EXPORTS', true),
        'expire_stale_email_changes' => (bool) env('SETTINGS_SCHEDULER_EXPIRE_EMAIL_CHANGES', true),
    ],

];
