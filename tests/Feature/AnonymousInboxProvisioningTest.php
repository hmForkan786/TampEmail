<?php

use App\Actions\Inbox\CreateInboxAction;
use App\DTOs\Inbox\CreateInboxData;
use App\DTOs\Inbox\InboxMutationContext;
use App\Enums\InboxType;
use App\Exceptions\EligibleMailServerUnavailableException;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\MailServer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function anonymousInboxData(string $domainId): CreateInboxData
{
    return new CreateInboxData(
        domainId: $domainId,
        userId: null,
        localPart: 'anonymous-'.uniqid(),
        fullAddress: uniqid().'@example.test',
        displayName: null,
        inboxType: InboxType::Temporary,
        expiresAt: now()->addHour(),
        metadata: null,
    );
}

function createAnonymousInbox(string $domainId): Inbox
{
    return app(CreateInboxAction::class)->execute(
        anonymousInboxData($domainId),
        null,
        InboxMutationContext::forAnonymous(),
    );
}

function eligibleMailServer(array $overrides = []): MailServer
{
    return MailServer::create(array_merge([
        'name' => 'Public inbound',
        'hostname' => 'public.example.test',
        'provider' => 'smtp',
        'protocol' => 'smtp',
        'is_active' => true,
        'priority' => 1,
        'last_health_check_at' => now(),
        'pool_key' => 'public',
        'max_inboxes' => null,
    ], $overrides));
}

beforeEach(function (): void {
    $this->domain = Domain::create([
        'domain' => 'example.test',
        'display_name' => 'Example',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'priority' => 1,
        'max_mailboxes' => null,
        'retention_hours' => 24,
        'metadata' => null,
    ]);
});

it('selects and persists a server from the configured public pool', function (): void {
    config(['inbox.public_mail_server_pool' => 'public']);
    $server = eligibleMailServer();

    $inbox = createAnonymousInbox($this->domain->id);

    expect($inbox->mail_server_id)->toBe($server->id);
    $this->assertDatabaseHas('inboxes', ['id' => $inbox->id, 'mail_server_id' => $server->id]);
});

it('fails closed when the public pool is missing or blank', function (?string $pool): void {
    config(['inbox.public_mail_server_pool' => $pool]);
    eligibleMailServer();

    expect(fn () => createAnonymousInbox($this->domain->id))
        ->toThrow(EligibleMailServerUnavailableException::class);
})->with([null, '', '   ']);

it('does not select null or different pools', function (): void {
    config(['inbox.public_mail_server_pool' => 'public']);
    eligibleMailServer(['pool_key' => null]);
    eligibleMailServer(['pool_key' => 'private']);

    expect(fn () => createAnonymousInbox($this->domain->id))
        ->toThrow(EligibleMailServerUnavailableException::class);
});

it('rejects a full public server', function (): void {
    config(['inbox.public_mail_server_pool' => 'public']);
    $server = eligibleMailServer(['max_inboxes' => 1]);
    Inbox::create(anonymousInboxData($this->domain->id)->withMailServerId($server->id)->toArray());

    expect(fn () => createAnonymousInbox($this->domain->id))
        ->toThrow(EligibleMailServerUnavailableException::class);
});

it('does not count inactive, expired, or deleted inboxes toward capacity', function (): void {
    config(['inbox.public_mail_server_pool' => 'public']);
    $server = eligibleMailServer(['max_inboxes' => 1]);

    $inactive = Inbox::create(anonymousInboxData($this->domain->id)->withMailServerId($server->id)->toArray());
    $inactive->update(['is_active' => false]);

    $expired = Inbox::create(anonymousInboxData($this->domain->id)->withMailServerId($server->id)->toArray());
    $expired->update(['expires_at' => now()->subMinute()]);

    $deleted = Inbox::create(anonymousInboxData($this->domain->id)->withMailServerId($server->id)->toArray());
    $deleted->delete();

    $created = createAnonymousInbox($this->domain->id);

    expect($created->mail_server_id)->toBe($server->id);
});
