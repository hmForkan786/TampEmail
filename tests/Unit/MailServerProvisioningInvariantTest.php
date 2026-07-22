<?php

use App\Actions\MailServer\CreateMailServerAction;
use App\DTOs\MailServer\CreateMailServerData;
use App\DTOs\MailServer\MailServerMutationContext;
use App\Services\Audit\AuditLogWriter;
use App\Exceptions\InvalidMailServerProvisioningDataException;
use App\Repositories\Contracts\MailServerRepositoryInterface;
use App\Support\MailServerProvisioningInvariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('accepts unlimited capacity and normalizes a valid pool key', function (): void {
    expect(MailServerProvisioningInvariant::poolKey('  primary  '))->toBe('primary')
        ->and(MailServerProvisioningInvariant::maxInboxes(null))->toBeNull();
});

it('rejects blank pool keys and non-positive capacities', function (): void {
    expect(fn () => MailServerProvisioningInvariant::poolKey(" \t"))
        ->toThrow(InvalidMailServerProvisioningDataException::class);
    expect(fn () => MailServerProvisioningInvariant::maxInboxes(0))
        ->toThrow(InvalidMailServerProvisioningDataException::class);
    expect(fn () => MailServerProvisioningInvariant::maxInboxes(-1))
        ->toThrow(InvalidMailServerProvisioningDataException::class);
});

it('rejects invalid create data before repository persistence', function (): void {
    $repository = Mockery::mock(MailServerRepositoryInterface::class);
    $repository->shouldNotReceive('create');

    $data = new CreateMailServerData(
        name: 'Inbound', hostname: 'mail.example.test', provider: 'smtp', protocol: 'smtp',
        isActive: true, priority: 0, maxConnections: 100, timeoutSeconds: 30,
        lastHealthCheckAt: null, metadata: null, poolKey: '   ', maxInboxes: 0,
    );

    expect(fn () => MailServerProvisioningInvariant::poolKey($data->poolKey))
        ->toThrow(InvalidMailServerProvisioningDataException::class);
});
