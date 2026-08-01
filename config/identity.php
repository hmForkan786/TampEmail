<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | Modes: disabled | open | invite_only. Unknown values fail closed to
    | disabled. Production must never silently open registration.
    |
    */
    'registration' => [
        'mode' => env('REGISTRATION_MODE', 'disabled'),
        'email_verification_required' => (bool) env('REGISTRATION_EMAIL_VERIFICATION_REQUIRED', true),
        'default_plan' => env('REGISTRATION_DEFAULT_PLAN', 'free'),
        'terms_required' => (bool) env('REGISTRATION_TERMS_REQUIRED', true),
        'min_age_confirmation' => (bool) env('REGISTRATION_MIN_AGE_CONFIRMATION', false),
        'honeypot_enabled' => (bool) env('REGISTRATION_HONEYPOT_ENABLED', true),
        'honeypot_field' => env('REGISTRATION_HONEYPOT_FIELD', 'website'),
        'min_form_fill_ms' => (int) env('REGISTRATION_MIN_FORM_FILL_MS', 1500),
        'block_disposable_emails' => (bool) env('REGISTRATION_BLOCK_DISPOSABLE_EMAILS', false),
        'disposable_domains' => array_values(array_filter(array_map(
            'strtolower',
            array_map('trim', explode(',', (string) env('REGISTRATION_DISPOSABLE_DOMAINS', 'mailinator.com,guerrillamail.com,tempmail.com')))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password policy
    |--------------------------------------------------------------------------
    */
    'password' => [
        'min_length' => (int) env('PASSWORD_MIN_LENGTH', 12),
        'require_mixed_case' => (bool) env('PASSWORD_REQUIRE_MIXED_CASE', true),
        'require_number' => (bool) env('PASSWORD_REQUIRE_NUMBER', true),
        'require_symbol' => (bool) env('PASSWORD_REQUIRE_SYMBOL', true),
        'uncompromised_check' => (bool) env('PASSWORD_UNCOMPROMISED_CHECK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email verification
    |--------------------------------------------------------------------------
    */
    'email_verification' => [
        'expire_minutes' => (int) env('EMAIL_VERIFICATION_EXPIRE_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password reset
    |--------------------------------------------------------------------------
    */
    'password_reset' => [
        'expire_minutes' => (int) env('PASSWORD_RESET_EXPIRE_MINUTES', 60),
        'throttle_seconds' => (int) env('PASSWORD_RESET_THROTTLE_SECONDS', 60),
        'revoke_sessions' => (bool) env('PASSWORD_RESET_REVOKE_SESSIONS', true),
        'revoke_api_keys' => (bool) env('PASSWORD_RESET_REVOKE_API_KEYS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sessions
    |--------------------------------------------------------------------------
    */
    'sessions' => [
        'max_active_web_sessions' => (int) env('MAX_ACTIVE_WEB_SESSIONS', 0),
        'remember_me_enabled' => (bool) env('IDENTITY_REMEMBER_ME_ENABLED', true),
        'enumeration_supported' => env('SESSION_DRIVER', 'database') === 'database',
    ],

    /*
    |--------------------------------------------------------------------------
    | Account recovery
    |--------------------------------------------------------------------------
    */
    'recovery' => [
        'dual_approval_email_change' => (bool) env('IDENTITY_RECOVERY_DUAL_APPROVAL', false),
        'expire_hours' => (int) env('IDENTITY_RECOVERY_EXPIRE_HOURS', 72),
        'revoke_api_keys_on_complete' => (bool) env('IDENTITY_RECOVERY_REVOKE_API_KEYS', true),
        'require_password_reset_after' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Account closure
    |--------------------------------------------------------------------------
    */
    'closure' => [
        'grace_days' => (int) env('ACCOUNT_CLOSURE_GRACE_DAYS', 7),
        'restore_supported' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | CAPTCHA / challenge hook (disabled by default — no provider in Prompt 664)
    |--------------------------------------------------------------------------
    */
    'challenge' => [
        'enabled' => (bool) env('IDENTITY_CHALLENGE_ENABLED', false),
        'provider' => env('IDENTITY_CHALLENGE_PROVIDER', 'none'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hashing / privacy
    |--------------------------------------------------------------------------
    */
    'hash_key' => env('IDENTITY_HASH_KEY', env('APP_KEY')),

    /*
    |--------------------------------------------------------------------------
    | Retention / pruning
    |--------------------------------------------------------------------------
    */
    'prune' => [
        'enabled' => (bool) env('IDENTITY_PRUNE_ENABLED', false),
        'unverified_retention_days' => (int) env('IDENTITY_UNVERIFIED_RETENTION_DAYS', 7),
        'login_history_days' => (int) env('IDENTITY_LOGIN_HISTORY_DAYS', 90),
        'batch_size' => (int) env('IDENTITY_PRUNE_BATCH_SIZE', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'registration_per_minute' => (int) env('RATE_LIMIT_REGISTRATION_PER_MINUTE', 5),
        'password_reset_per_minute' => (int) env('RATE_LIMIT_PASSWORD_RESET_PER_MINUTE', 5),
        'verification_resend_per_minute' => (int) env('RATE_LIMIT_VERIFICATION_RESEND_PER_MINUTE', 3),
        'recovery_per_minute' => (int) env('RATE_LIMIT_RECOVERY_PER_MINUTE', 3),
        'invite_failures_per_hour' => (int) env('RATE_LIMIT_INVITE_FAILURES_PER_HOUR', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduler
    |--------------------------------------------------------------------------
    */
    'scheduler' => [
        'prune_login_history' => (bool) env('IDENTITY_SCHEDULER_PRUNE_LOGIN_HISTORY', true),
        'expire_invites' => (bool) env('IDENTITY_SCHEDULER_EXPIRE_INVITES', true),
        'expire_recovery' => (bool) env('IDENTITY_SCHEDULER_EXPIRE_RECOVERY', true),
        'prune_unverified' => (bool) env('IDENTITY_SCHEDULER_PRUNE_UNVERIFIED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted devices foundation (disabled)
    |--------------------------------------------------------------------------
    */
    'trusted_devices' => [
        'enabled' => false,
    ],

];
