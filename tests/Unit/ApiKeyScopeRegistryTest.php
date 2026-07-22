<?php

use App\Enums\ApiKeyScope;
use App\Enums\PlatformRole;
use App\Exceptions\InvalidApiKeyScopeException;
use App\Services\ApiKey\ApiKeyScopeRegistry;

it('accepts every known canonical scope', function (): void {
    $scopes = ApiKeyScopeRegistry::values();

    expect($scopes)->toBe([
        'mail_servers:read',
        'mail_servers:write',
        'mail_servers:admin',
        'inboxes:read',
        'inboxes:write',
    ])->and(ApiKeyScopeRegistry::normalize($scopes))->toBe($scopes);

    foreach ($scopes as $scope) {
        expect(ApiKeyScopeRegistry::isKnown($scope))->toBeTrue();
    }
});

it('rejects unknown scopes', function (): void {
    expect(ApiKeyScopeRegistry::isKnown('mail_servers:super'))->toBeFalse();

    expect(fn () => ApiKeyScopeRegistry::normalize(['mail_servers:read', 'not:a_scope']))
        ->toThrow(InvalidApiKeyScopeException::class);
});

it('rejects blank scopes', function (): void {
    expect(fn () => ApiKeyScopeRegistry::normalize(['']))
        ->toThrow(InvalidApiKeyScopeException::class);

    expect(fn () => ApiKeyScopeRegistry::normalize(['   ']))
        ->toThrow(InvalidApiKeyScopeException::class);
});

it('rejects non-string scopes', function (): void {
    expect(fn () => ApiKeyScopeRegistry::normalize([123]))
        ->toThrow(InvalidApiKeyScopeException::class);

    expect(fn () => ApiKeyScopeRegistry::normalize([null]))
        ->toThrow(InvalidApiKeyScopeException::class);
});

it('removes duplicates while preserving stable enum order', function (): void {
    expect(ApiKeyScopeRegistry::normalize([
        'inboxes:write',
        'mail_servers:admin',
        'mail_servers:read',
        'mail_servers:admin',
        'inboxes:read',
        'mail_servers:write',
        'mail_servers:read',
    ]))->toBe([
        'mail_servers:read',
        'mail_servers:write',
        'mail_servers:admin',
        'inboxes:read',
        'inboxes:write',
    ]);
});

it('maps required owner capabilities correctly', function (): void {
    expect(ApiKeyScopeRegistry::requiredCapability(ApiKeyScope::MailServersRead))->toBe(PlatformRole::Operator)
        ->and(ApiKeyScopeRegistry::requiredCapability(ApiKeyScope::MailServersWrite))->toBe(PlatformRole::Operator)
        ->and(ApiKeyScopeRegistry::requiredCapability(ApiKeyScope::MailServersAdmin))->toBe(PlatformRole::Admin)
        ->and(ApiKeyScopeRegistry::requiredCapability(ApiKeyScope::InboxesRead))->toBe(PlatformRole::User)
        ->and(ApiKeyScopeRegistry::requiredCapability(ApiKeyScope::InboxesWrite))->toBe(PlatformRole::User)
        ->and(ApiKeyScope::MailServersAdmin->requiresAdmin())->toBeTrue()
        ->and(ApiKeyScope::MailServersRead->requiresAdmin())->toBeFalse()
        ->and(ApiKeyScope::MailServersWrite->requiresOperator())->toBeTrue();
});

it('does not treat pool entitlements as API key scopes', function (): void {
    expect(ApiKeyScopeRegistry::isKnown('mail_server_pools'))->toBeFalse()
        ->and(ApiKeyScopeRegistry::isKnown('max_inboxes'))->toBeFalse();

    expect(fn () => ApiKeyScopeRegistry::normalize(['mail_server_pools']))
        ->toThrow(InvalidApiKeyScopeException::class);
});

it('does not convert whitespace-padded scopes into privileges', function (): void {
    expect(ApiKeyScopeRegistry::isKnown(' mail_servers:read'))->toBeFalse()
        ->and(ApiKeyScopeRegistry::isKnown('mail_servers:read '))->toBeFalse();

    expect(fn () => ApiKeyScopeRegistry::normalize([' mail_servers:read']))
        ->toThrow(InvalidApiKeyScopeException::class);
});
