<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function reloadInboxConfig(?string $publicMailServerPool): array
{
    if ($publicMailServerPool === null) {
        putenv('PUBLIC_MAIL_SERVER_POOL');
        unset($_ENV['PUBLIC_MAIL_SERVER_POOL'], $_SERVER['PUBLIC_MAIL_SERVER_POOL']);
    } else {
        putenv('PUBLIC_MAIL_SERVER_POOL='.$publicMailServerPool);
        $_ENV['PUBLIC_MAIL_SERVER_POOL'] = $publicMailServerPool;
        $_SERVER['PUBLIC_MAIL_SERVER_POOL'] = $publicMailServerPool;
    }

    return require config_path('inbox.php');
}

it('loads a configured public mail server pool key', function (): void {
    $config = reloadInboxConfig('anonymous-primary');

    expect($config['public_mail_server_pool'])->toBe('anonymous-primary');
});

it('treats an empty public mail server pool config as disabled anonymous provisioning', function (): void {
    $config = reloadInboxConfig('');

    expect($config['public_mail_server_pool'])->toBeNull();
});

it('treats a missing public mail server pool config as disabled anonymous provisioning', function (): void {
    $config = reloadInboxConfig(null);

    expect($config['public_mail_server_pool'])->toBeNull();
});

it('never treats whitespace as a public pool key', function (): void {
    expect(reloadInboxConfig('   ')['public_mail_server_pool'])->toBeNull();
});

it('normalizes public mail server pool keys by trimming whitespace', function (): void {
    expect(reloadInboxConfig('  public-pool  ')['public_mail_server_pool'])->toBe('public-pool');
});

it('does not treat null pool keys as anonymous provisioning eligibility', function (): void {
    expect(reloadInboxConfig(null)['public_mail_server_pool'])->toBeNull();
    expect(reloadInboxConfig('')['public_mail_server_pool'])->toBeNull();
    expect(reloadInboxConfig('   ')['public_mail_server_pool'])->toBeNull();
});

it('keeps authenticated entitlement configuration separate from the public pool', function (): void {
    reloadInboxConfig('anonymous-primary');

    expect(config('inbox'))->toHaveKey('public_mail_server_pool');
    expect(config('api'))->not->toHaveKey('public_mail_server_pool');
    expect(config('inbox'))->not->toHaveKey('mail_server_pools');
});
