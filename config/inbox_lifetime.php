<?php

declare(strict_types=1);

/**
 * Inbox lifetime and renewal policy configuration.
 *
 * Policy document: docs/INBOX_LIFETIME_POLICY.md
 *
 * Invalid numeric values fail closed to 0 (disabled / unusable for renewal math).
 * Booleans that gate revive, shorten, anonymous renewal, and soft-deleted renewal
 * are hard-coded fail-closed and are not overridable from the environment.
 */
$boundedHours = static function (string $envKey, int $default, int $min, int $max, ?string $legacyKey = null): int {
    $raw = env($envKey, null);
    if ($raw === null && $legacyKey !== null) $raw = env($legacyKey, null);
    $value = $raw === null ? $default : (is_numeric($raw) ? (int) $raw : 0);

    return ($value >= $min && $value <= $max) ? $value : 0;
};

$boundedCount = static function (string $envKey, int $default, int $min, int $max): int {
    $raw = env($envKey, null);
    $value = $raw === null ? $default : (is_numeric($raw) ? (int) $raw : 0);

    return ($value >= $min && $value <= $max) ? $value : 0;
};

return [
    /*
    |--------------------------------------------------------------------------
    | Renewal feature gate
    |--------------------------------------------------------------------------
    |
    | Runtime renewal endpoints must remain disabled until an implementation
    | prompt wires PATCH /api/v1/inboxes/{inbox}/expiration. Default false.
    |
    */
    'renewal_enabled' => filter_var(env('INBOX_RENEWAL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'expiration_scheduler_enabled' => filter_var(env('INBOX_EXPIRATION_SCHEDULER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'expiration_batch_size' => $boundedCount('INBOX_EXPIRATION_BATCH_SIZE', 100, 1, 10000),

    /*
    |--------------------------------------------------------------------------
    | Creation lifetime defaults (hours)
    |--------------------------------------------------------------------------
    |
    | Aligns with legacy INBOX_DEFAULT_TTL=86400 seconds (24h). Anonymous and
    | user-owned creation share the same default; only user-owned inboxes may
    | later renew when renewal_enabled is true.
    |
    */
    'default_lifetime_hours' => $boundedHours('INBOX_DEFAULT_LIFETIME_HOURS', 24, 1, 168, 'INBOX_DEFAULT_TTL_HOURS'),

    'min_lifetime_hours' => $boundedHours('INBOX_MIN_LIFETIME_HOURS', 1, 1, 24),

    /*
    |--------------------------------------------------------------------------
    | Renewal ceilings (hours)
    |--------------------------------------------------------------------------
    */
    'max_extension_hours_per_request' => $boundedHours('INBOX_MAX_EXTENSION_HOURS_PER_REQUEST', 24, 1, 168, 'INBOX_RENEWAL_MAX_EXTENSION_HOURS'),

    /*
    | Platform absolute ceiling from created_at. Matches the historical
    | StoreOwnedInboxRequest fallback of 720 hours (30 days) and aligns with
    | legacy INBOX_PREMIUM_TTL=2592000 seconds.
    */
    'max_absolute_lifetime_hours' => $boundedHours('INBOX_MAX_ABSOLUTE_LIFETIME_HOURS', 720, 24, 8760, 'INBOX_MAX_LIFETIME_HOURS'),

    /*
    |--------------------------------------------------------------------------
    | Hard fail-closed product gates (not env-overridable)
    |--------------------------------------------------------------------------
    */
    'anonymous_renewal_enabled' => false,
    'allow_expiry_shorten' => false,
    'allow_expired_revive' => false,
    'allow_inactive_renewal' => false,
    'allow_soft_deleted_renewal' => false,
    'require_owner' => true,
    'require_active_domain' => true,
    'preserve_mail_server_assignment' => true,

    /*
    |--------------------------------------------------------------------------
    | Abuse controls
    |--------------------------------------------------------------------------
    |
    | Per-owner renewal attempts per rolling hour. Zero means fail closed
    | (renewal rate limit unusable). Distinct from inbox creation limits in
    | config/abuse.php.
    |
    */
    'renewals_per_hour' => $boundedCount('RATE_LIMIT_INBOX_RENEWAL_PER_HOUR', 10, 1, 120),

    /*
    |--------------------------------------------------------------------------
    | Declared bounds (documentation + validation tests)
    |--------------------------------------------------------------------------
    */
    'bounds' => [
        'default_lifetime_hours' => ['min' => 1, 'max' => 168],
        'min_lifetime_hours' => ['min' => 1, 'max' => 24],
        'max_extension_hours_per_request' => ['min' => 1, 'max' => 168],
        'max_absolute_lifetime_hours' => ['min' => 24, 'max' => 8760],
        'renewals_per_hour' => ['min' => 1, 'max' => 120],
    ],

    /*
    |--------------------------------------------------------------------------
    | Proposed plan entitlement (not issued by this prompt)
    |--------------------------------------------------------------------------
    |
    | Feature key: inbox_max_lifetime_hours
    | Value type: JSON object {"limit": <int hours>|null}
    | Semantics: see docs/INBOX_LIFETIME_POLICY.md § Entitlement
    |
    */
    'entitlement_key' => 'inbox_max_lifetime_hours',
];
