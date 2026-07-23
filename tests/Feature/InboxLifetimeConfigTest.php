<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function reloadInboxLifetimeConfig(array $env): array
{
    $keys = [
        'INBOX_RENEWAL_ENABLED',
        'INBOX_DEFAULT_LIFETIME_HOURS',
        'INBOX_MIN_LIFETIME_HOURS',
        'INBOX_MAX_EXTENSION_HOURS_PER_REQUEST',
        'INBOX_MAX_ABSOLUTE_LIFETIME_HOURS',
        'RATE_LIMIT_INBOX_RENEWAL_PER_HOUR',
    ];

    foreach ($keys as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    foreach ($env as $key => $value) {
        if ($value === null) {
            continue;
        }
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    return require config_path('inbox_lifetime.php');
}

it('loads fail-closed renewal defaults aligned with the lifetime policy', function (): void {
    $config = reloadInboxLifetimeConfig([]);

    expect($config['renewal_enabled'])->toBeFalse()
        ->and($config['default_lifetime_hours'])->toBe(24)
        ->and($config['min_lifetime_hours'])->toBe(1)
        ->and($config['max_extension_hours_per_request'])->toBe(24)
        ->and($config['max_absolute_lifetime_hours'])->toBe(720)
        ->and($config['renewals_per_hour'])->toBe(10)
        ->and($config['anonymous_renewal_enabled'])->toBeFalse()
        ->and($config['allow_expiry_shorten'])->toBeFalse()
        ->and($config['allow_expired_revive'])->toBeFalse()
        ->and($config['allow_inactive_renewal'])->toBeFalse()
        ->and($config['allow_soft_deleted_renewal'])->toBeFalse()
        ->and($config['require_owner'])->toBeTrue()
        ->and($config['require_active_domain'])->toBeTrue()
        ->and($config['preserve_mail_server_assignment'])->toBeTrue()
        ->and($config['entitlement_key'])->toBe('inbox_max_lifetime_hours');
});

it('accepts configured values within declared bounds', function (): void {
    $config = reloadInboxLifetimeConfig([
        'INBOX_RENEWAL_ENABLED' => 'true',
        'INBOX_DEFAULT_LIFETIME_HOURS' => '48',
        'INBOX_MIN_LIFETIME_HOURS' => '2',
        'INBOX_MAX_EXTENSION_HOURS_PER_REQUEST' => '72',
        'INBOX_MAX_ABSOLUTE_LIFETIME_HOURS' => '1440',
        'RATE_LIMIT_INBOX_RENEWAL_PER_HOUR' => '30',
    ]);

    expect($config['renewal_enabled'])->toBeTrue()
        ->and($config['default_lifetime_hours'])->toBe(48)
        ->and($config['min_lifetime_hours'])->toBe(2)
        ->and($config['max_extension_hours_per_request'])->toBe(72)
        ->and($config['max_absolute_lifetime_hours'])->toBe(1440)
        ->and($config['renewals_per_hour'])->toBe(30);
});

it('fails closed to zero for out-of-bounds lifetime and rate-limit values', function (): void {
    $config = reloadInboxLifetimeConfig([
        'INBOX_DEFAULT_LIFETIME_HOURS' => '0',
        'INBOX_MIN_LIFETIME_HOURS' => '100',
        'INBOX_MAX_EXTENSION_HOURS_PER_REQUEST' => '-5',
        'INBOX_MAX_ABSOLUTE_LIFETIME_HOURS' => '10',
        'RATE_LIMIT_INBOX_RENEWAL_PER_HOUR' => '999',
    ]);

    expect($config['default_lifetime_hours'])->toBe(0)
        ->and($config['min_lifetime_hours'])->toBe(0)
        ->and($config['max_extension_hours_per_request'])->toBe(0)
        ->and($config['max_absolute_lifetime_hours'])->toBe(0)
        ->and($config['renewals_per_hour'])->toBe(0);
});

it('keeps revive shorten anonymous and soft-delete renewal gates non-overridable', function (): void {
    putenv('INBOX_ANONYMOUS_RENEWAL_ENABLED=true');
    $_ENV['INBOX_ANONYMOUS_RENEWAL_ENABLED'] = 'true';
    putenv('INBOX_ALLOW_EXPIRED_REVIVE=true');
    $_ENV['INBOX_ALLOW_EXPIRED_REVIVE'] = 'true';
    putenv('INBOX_ALLOW_EXPIRY_SHORTEN=true');
    $_ENV['INBOX_ALLOW_EXPIRY_SHORTEN'] = 'true';

    $config = reloadInboxLifetimeConfig([
        'INBOX_RENEWAL_ENABLED' => 'true',
    ]);

    expect($config['anonymous_renewal_enabled'])->toBeFalse()
        ->and($config['allow_expired_revive'])->toBeFalse()
        ->and($config['allow_expiry_shorten'])->toBeFalse()
        ->and($config['allow_inactive_renewal'])->toBeFalse()
        ->and($config['allow_soft_deleted_renewal'])->toBeFalse();

    putenv('INBOX_ANONYMOUS_RENEWAL_ENABLED');
    unset($_ENV['INBOX_ANONYMOUS_RENEWAL_ENABLED'], $_SERVER['INBOX_ANONYMOUS_RENEWAL_ENABLED']);
    putenv('INBOX_ALLOW_EXPIRED_REVIVE');
    unset($_ENV['INBOX_ALLOW_EXPIRED_REVIVE'], $_SERVER['INBOX_ALLOW_EXPIRED_REVIVE']);
    putenv('INBOX_ALLOW_EXPIRY_SHORTEN');
    unset($_ENV['INBOX_ALLOW_EXPIRY_SHORTEN'], $_SERVER['INBOX_ALLOW_EXPIRY_SHORTEN']);
});

it('declares bounds that match the policy document and do not conflict with create fallback', function (): void {
    $config = reloadInboxLifetimeConfig([]);

    expect($config['bounds']['default_lifetime_hours'])->toBe(['min' => 1, 'max' => 168])
        ->and($config['bounds']['min_lifetime_hours'])->toBe(['min' => 1, 'max' => 24])
        ->and($config['bounds']['max_extension_hours_per_request'])->toBe(['min' => 1, 'max' => 168])
        ->and($config['bounds']['max_absolute_lifetime_hours'])->toBe(['min' => 24, 'max' => 8760])
        ->and($config['bounds']['renewals_per_hour'])->toBe(['min' => 1, 'max' => 120])
        ->and($config['max_absolute_lifetime_hours'])->toBe(720);

    expect((int) config('inbox_lifetime.max_absolute_lifetime_hours'))->toBe(720);

    // Expiration scheduler config stays separate and disabled by default.
    expect(config('inboxes.expiration.enabled'))->toBeFalse()
        ->and(config('inbox_lifetime'))->not->toHaveKey('expiration');
});

it('keeps legacy second-based TTL env vars distinct from canonical hour keys', function (): void {
    $config = reloadInboxLifetimeConfig([
        'INBOX_DEFAULT_LIFETIME_HOURS' => '24',
    ]);

    expect($config)->toHaveKey('default_lifetime_hours')
        ->and($config)->not->toHaveKey('default_ttl')
        ->and($config)->not->toHaveKey('premium_ttl')
        ->and($config['default_lifetime_hours'] * 3600)->toBe(86400);
});
