<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Actions\Inbox\CreateInboxAction;
use App\Actions\Inbox\DeleteInboxAction;
use App\DTOs\Inbox\CreateInboxData;
use App\DTOs\Inbox\InboxMutationContext;
use App\Enums\BillingCycle;
use App\Enums\InboxType;
use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Exceptions\EligibleMailServerUnavailableException;
use App\Exceptions\InboxQuotaExceededException;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Feature;
use App\Models\Inbox;
use App\Models\MailServer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'inbox-lifecycle-audit-test-secret']);
});

/**
 * @return array{0: User, 1: string, 2: \App\Models\ApiKey}
 */
function lifecycleAuditApiKey(User $user, array $scopes = ['inboxes:write']): array
{
    $issued = app(CreateApiKeyAction::class)->issue(
        userId: $user->id,
        name: 'inbox-lifecycle-audit',
        permissions: $scopes,
        user: $user,
    );

    return [$user, $issued->plainToken, $issued->apiKey];
}

/**
 * @return array{user: User, domain: Domain, mailServer: MailServer, token: string, apiKey: \App\Models\ApiKey, poolKey: string}
 */
function lifecycleAuditEntitledContext(int $inboxLimit = 5, ?int $serverCapacity = null): array
{
    $user = User::factory()->create();
    [, $token, $apiKey] = lifecycleAuditApiKey($user);
    $poolKey = 'lifecycle-'.bin2hex(random_bytes(4));

    $plan = Plan::create([
        'slug' => 'lifecycle-audit-'.uniqid(),
        'name' => 'Lifecycle Audit',
        'price_monthly' => '0.00',
        'price_yearly' => '0.00',
        'currency' => 'USD',
        'is_free' => true,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $maxInboxesFeature = Feature::query()->firstOrCreate(
        ['key' => 'max_inboxes'],
        ['name' => 'Max Inboxes', 'value_type' => ValueType::Json, 'is_active' => true, 'display_order' => 1],
    );
    $poolsFeature = Feature::query()->firstOrCreate(
        ['key' => 'mail_server_pools'],
        ['name' => 'Mail Server Pools', 'value_type' => ValueType::Json, 'is_active' => true, 'display_order' => 2],
    );

    $plan->features()->attach($maxInboxesFeature->id, ['feature_value' => ['limit' => $inboxLimit]]);
    $plan->features()->attach($poolsFeature->id, ['feature_value' => ['pools' => [$poolKey]]]);

    Subscription::create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly,
        'starts_at' => now()->subDay(),
        'auto_renew' => true,
        'price' => '0.00',
        'currency' => 'USD',
    ]);

    $domain = Domain::create([
        'domain' => 'lifecycle-'.uniqid().'.example.test',
        'display_name' => 'Lifecycle',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'priority' => 1,
        'max_mailboxes' => null,
        'retention_hours' => 24,
        'metadata' => null,
    ]);

    $mailServer = MailServer::create([
        'name' => 'Lifecycle inbound',
        'hostname' => 'lifecycle-'.uniqid().'.example.test',
        'provider' => 'smtp',
        'protocol' => 'smtp',
        'is_active' => true,
        'priority' => 1,
        'last_health_check_at' => now(),
        'pool_key' => $poolKey,
        'max_inboxes' => $serverCapacity,
        'metadata' => ['token' => 'server-fixture-marker', 'password' => 'server-fixture-marker'],
    ]);

    return compact('user', 'domain', 'mailServer', 'token', 'apiKey', 'poolKey');
}

function lifecycleAuditCreateData(Domain $domain, ?User $user, string $localPart): CreateInboxData
{
    return new CreateInboxData(
        domainId: $domain->id,
        userId: $user?->id,
        localPart: $localPart,
        fullAddress: strtolower($localPart).'@'.strtolower($domain->domain),
        displayName: null,
        inboxType: InboxType::Temporary,
        expiresAt: now()->addHour(),
        metadata: [
            'authorization' => 'Bearer fixture-marker',
            'raw_payload' => 'fixture-marker',
            'headers' => ['X-Secret' => 'fixture-marker'],
        ],
    );
}

function assertLifecycleAuditPayloadIsSafe(AuditLog $audit, Inbox $inbox, MailServer $mailServer): void
{
    $values = json_encode([
        'old' => $audit->old_values,
        'new' => $audit->new_values,
        'meta' => $audit->metadata,
    ]);

    expect($audit->new_values ?? [])->not->toHaveKeys([
        'full_address',
        'local_part',
        'mail_server_id',
        'metadata',
        'display_name',
    ])
        ->and($audit->old_values ?? [])->not->toHaveKeys([
            'full_address',
            'local_part',
            'mail_server_id',
            'metadata',
        ])
        ->and($audit->metadata ?? [])->not->toHaveKeys([
            'full_address',
            'local_part',
            'mail_server_id',
            'pool_key',
            'password',
            'token',
            'authorization',
            'headers',
            'raw_payload',
        ])
        ->and($values)->not->toContain($inbox->full_address)
        ->and($values)->not->toContain($inbox->local_part)
        ->and($values)->not->toContain((string) $inbox->mail_server_id)
        ->and($values)->not->toContain((string) $mailServer->pool_key)
        ->and($values)->not->toContain('fixture-marker')
        ->and($values)->not->toContain('Bearer ');
}

it('audits authenticated inbox create with actor api key and api source', function (): void {
    $ctx = lifecycleAuditEntitledContext();
    $localPart = 'create'.bin2hex(random_bytes(3));

    $response = $this->withToken($ctx['token'])->postJson('/api/v1/inboxes', [
        'domain_id' => $ctx['domain']->id,
        'local_part' => $localPart,
        'expires_at' => now()->addHour()->toIso8601String(),
    ]);
    $response->assertCreated();

    $inbox = Inbox::query()->findOrFail($response->json('data.id'));
    $audit = AuditLog::query()->where('action', 'inbox.created')->sole();

    expect(AuditLog::query()->count())->toBe(1)
        ->and($audit->user_id)->toBe((string) $ctx['user']->id)
        ->and($audit->auditable_type)->toBe(Inbox::class)
        ->and($audit->auditable_id)->toBe((string) $inbox->id)
        ->and($audit->old_values)->toBe([])
        ->and($audit->new_values)->toHaveKeys(['is_active', 'expires_at'])
        ->and($audit->new_values['is_active'])->toBeTrue()
        ->and($audit->metadata['source'])->toBe('api')
        ->and($audit->metadata['api_key_id'])->toBe((string) $ctx['apiKey']->id)
        ->and($audit->metadata['domain_id'])->toBe((string) $ctx['domain']->id)
        ->and($audit->metadata['anonymous'])->toBeFalse()
        ->and($audit->metadata)->toHaveKey('changed_at');

    assertLifecycleAuditPayloadIsSafe($audit, $inbox, $ctx['mailServer']);
});

it('audits authenticated deactivation with active state transition and actor mapping', function (): void {
    $ctx = lifecycleAuditEntitledContext();
    $created = $this->withToken($ctx['token'])->postJson('/api/v1/inboxes', [
        'domain_id' => $ctx['domain']->id,
        'local_part' => 'deact'.bin2hex(random_bytes(3)),
    ])->assertCreated();
    $inboxId = $created->json('data.id');

    $this->withToken($ctx['token'])->deleteJson('/api/v1/inboxes/'.$inboxId)->assertNoContent();

    $inbox = Inbox::withTrashed()->findOrFail($inboxId);
    $audit = AuditLog::query()->where('action', 'inbox.deactivated')->sole();

    expect($inbox->trashed())->toBeTrue()
        ->and($inbox->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'inbox.created')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'inbox.deactivated')->count())->toBe(1)
        ->and($audit->user_id)->toBe((string) $ctx['user']->id)
        ->and($audit->old_values)->toBe(['is_active' => true])
        ->and($audit->new_values)->toBe(['is_active' => false])
        ->and($audit->metadata['source'])->toBe('api')
        ->and($audit->metadata['api_key_id'])->toBe((string) $ctx['apiKey']->id)
        ->and($audit->metadata)->toHaveKey('changed_at')
        ->and($audit->metadata)->not->toHaveKey('pool_key');

    assertLifecycleAuditPayloadIsSafe($audit, $inbox, $ctx['mailServer']);
});

it('audits anonymous provisioning with null actor and anonymous source', function (): void {
    config(['inbox.public_mail_server_pool' => 'public']);
    $domain = Domain::create([
        'domain' => 'anon-lifecycle-'.uniqid().'.test',
        'display_name' => 'Anon',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'priority' => 1,
        'max_mailboxes' => null,
        'retention_hours' => 24,
        'metadata' => null,
    ]);
    $mailServer = MailServer::create([
        'name' => 'Public lifecycle',
        'hostname' => 'public-lifecycle-'.uniqid().'.test',
        'provider' => 'smtp',
        'protocol' => 'smtp',
        'is_active' => true,
        'priority' => 1,
        'last_health_check_at' => now(),
        'pool_key' => 'public',
        'max_inboxes' => null,
    ]);

    $localPart = 'anon'.bin2hex(random_bytes(3));
    $inbox = app(CreateInboxAction::class)->execute(
        lifecycleAuditCreateData($domain, null, $localPart),
        null,
        InboxMutationContext::forAnonymous(),
    );

    $audit = AuditLog::query()->where('action', 'inbox.created')->sole();

    expect($inbox->user_id)->toBeNull()
        ->and($inbox->mail_server_id)->toBe((string) $mailServer->id)
        ->and($audit->user_id)->toBeNull()
        ->and($audit->metadata['source'])->toBe('anonymous')
        ->and($audit->metadata['anonymous'])->toBeTrue()
        ->and($audit->metadata['api_key_id'])->toBeNull()
        ->and($audit->metadata)->not->toHaveKey('pool_key');

    assertLifecycleAuditPayloadIsSafe($audit, $inbox, $mailServer);
});

it('rolls back create when audit writing fails and leaves no inbox or audit row', function (): void {
    $ctx = lifecycleAuditEntitledContext();
    $writer = Mockery::mock(AuditLogWriter::class);
    $writer->shouldReceive('write')->once()->andThrow(new RuntimeException('audit write failed'));
    app()->instance(AuditLogWriter::class, $writer);

    $data = lifecycleAuditCreateData($ctx['domain'], $ctx['user'], 'rollback'.bin2hex(random_bytes(3)));
    $context = InboxMutationContext::forApi((string) $ctx['user']->id, (string) $ctx['apiKey']->id);

    expect(fn () => app(CreateInboxAction::class)->execute($data, $ctx['user'], $context))
        ->toThrow(RuntimeException::class);

    expect(Inbox::query()->where('user_id', $ctx['user']->id)->count())->toBe(0)
        ->and(Inbox::withTrashed()->where('user_id', $ctx['user']->id)->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('rolls back deactivation when audit writing fails and keeps the inbox active', function (): void {
    $ctx = lifecycleAuditEntitledContext();
    $inbox = app(CreateInboxAction::class)->execute(
        lifecycleAuditCreateData($ctx['domain'], $ctx['user'], 'keep'.bin2hex(random_bytes(3))),
        $ctx['user'],
        InboxMutationContext::forApi((string) $ctx['user']->id, (string) $ctx['apiKey']->id),
    );
    expect(AuditLog::query()->where('action', 'inbox.created')->count())->toBe(1);

    $writer = Mockery::mock(AuditLogWriter::class);
    $writer->shouldReceive('write')->once()->andThrow(new RuntimeException('audit write failed'));
    app()->instance(AuditLogWriter::class, $writer);

    expect(fn () => app(DeleteInboxAction::class)->execute(
        $inbox,
        InboxMutationContext::forApi((string) $ctx['user']->id, (string) $ctx['apiKey']->id),
    ))->toThrow(RuntimeException::class);

    $fresh = $inbox->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->trashed())->toBeFalse()
        ->and($fresh->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'inbox.deactivated')->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(1);
});

it('writes no audit when quota capacity or duplicate create fails', function (): void {
    $quotaCtx = lifecycleAuditEntitledContext(inboxLimit: 1);
    $first = app(CreateInboxAction::class)->execute(
        lifecycleAuditCreateData($quotaCtx['domain'], $quotaCtx['user'], 'quota'.bin2hex(random_bytes(2))),
        $quotaCtx['user'],
        InboxMutationContext::forApi((string) $quotaCtx['user']->id, (string) $quotaCtx['apiKey']->id),
    );
    expect(AuditLog::query()->count())->toBe(1);

    expect(fn () => app(CreateInboxAction::class)->execute(
        lifecycleAuditCreateData($quotaCtx['domain'], $quotaCtx['user'], 'quota2'.bin2hex(random_bytes(2))),
        $quotaCtx['user'],
        InboxMutationContext::forApi((string) $quotaCtx['user']->id, (string) $quotaCtx['apiKey']->id),
    ))->toThrow(InboxQuotaExceededException::class);
    expect(AuditLog::query()->count())->toBe(1)
        ->and(Inbox::query()->where('user_id', $quotaCtx['user']->id)->count())->toBe(1);

    $capacityCtx = lifecycleAuditEntitledContext(inboxLimit: 5, serverCapacity: 1);
    app(CreateInboxAction::class)->execute(
        lifecycleAuditCreateData($capacityCtx['domain'], $capacityCtx['user'], 'cap'.bin2hex(random_bytes(2))),
        $capacityCtx['user'],
        InboxMutationContext::forApi((string) $capacityCtx['user']->id, (string) $capacityCtx['apiKey']->id),
    );
    $auditsAfterCapacityFirst = AuditLog::query()->count();
    expect(fn () => app(CreateInboxAction::class)->execute(
        lifecycleAuditCreateData($capacityCtx['domain'], $capacityCtx['user'], 'cap2'.bin2hex(random_bytes(2))),
        $capacityCtx['user'],
        InboxMutationContext::forApi((string) $capacityCtx['user']->id, (string) $capacityCtx['apiKey']->id),
    ))->toThrow(EligibleMailServerUnavailableException::class);
    expect(AuditLog::query()->count())->toBe($auditsAfterCapacityFirst);

    $duplicateCtx = lifecycleAuditEntitledContext();
    $localPart = 'dup'.bin2hex(random_bytes(3));
    $data = lifecycleAuditCreateData($duplicateCtx['domain'], $duplicateCtx['user'], $localPart);
    app(CreateInboxAction::class)->execute(
        $data,
        $duplicateCtx['user'],
        InboxMutationContext::forApi((string) $duplicateCtx['user']->id, (string) $duplicateCtx['apiKey']->id),
    );
    $auditsAfterDuplicateFirst = AuditLog::query()->count();
    expect(fn () => app(CreateInboxAction::class)->execute(
        $data,
        $duplicateCtx['user'],
        InboxMutationContext::forApi((string) $duplicateCtx['user']->id, (string) $duplicateCtx['apiKey']->id),
    ))->toThrow(\Illuminate\Database\QueryException::class);
    expect(AuditLog::query()->count())->toBe($auditsAfterDuplicateFirst)
        ->and(Inbox::query()->where('full_address', $data->fullAddress)->count())->toBe(1);
});

it('writes no audit for foreign or repeated deactivation attempts', function (): void {
    $ownerCtx = lifecycleAuditEntitledContext();
    $otherCtx = lifecycleAuditEntitledContext();

    $created = $this->withToken($ownerCtx['token'])->postJson('/api/v1/inboxes', [
        'domain_id' => $ownerCtx['domain']->id,
        'local_part' => 'own'.bin2hex(random_bytes(3)),
    ])->assertCreated();
    $inboxId = $created->json('data.id');
    expect(AuditLog::query()->where('action', 'inbox.created')->count())->toBe(1);

    $this->withToken($otherCtx['token'])->deleteJson('/api/v1/inboxes/'.$inboxId)
        ->assertNotFound();
    expect(AuditLog::query()->where('action', 'inbox.deactivated')->count())->toBe(0)
        ->and(Inbox::query()->find($inboxId)?->is_active)->toBeTrue();

    $this->withToken($ownerCtx['token'])->deleteJson('/api/v1/inboxes/'.$inboxId)->assertNoContent();
    expect(AuditLog::query()->where('action', 'inbox.deactivated')->count())->toBe(1);

    $this->withToken($ownerCtx['token'])->deleteJson('/api/v1/inboxes/'.$inboxId)->assertNotFound();
    expect(AuditLog::query()->where('action', 'inbox.deactivated')->count())->toBe(1);

    $softDeleted = Inbox::withTrashed()->findOrFail($inboxId);
    expect(fn () => app(DeleteInboxAction::class)->execute(
        $softDeleted,
        InboxMutationContext::forApi((string) $ownerCtx['user']->id, (string) $ownerCtx['apiKey']->id),
    ))->toThrow(ModelNotFoundException::class);
    expect(AuditLog::query()->where('action', 'inbox.deactivated')->count())->toBe(1);
});

it('keeps audit writes inside the mutation transaction and applies payload sanitization', function (): void {
    $ctx = lifecycleAuditEntitledContext();
    $data = lifecycleAuditCreateData($ctx['domain'], $ctx['user'], 'txn'.bin2hex(random_bytes(3)));
    $context = InboxMutationContext::forApi((string) $ctx['user']->id, (string) $ctx['apiKey']->id);

    $observed = ['in_transaction' => false];
    $realWriter = new AuditLogWriter(app(\App\Services\Audit\AuditPayloadSanitizer::class));
    $writer = Mockery::mock(AuditLogWriter::class);
    $writer->shouldReceive('write')->once()->andReturnUsing(function (
        string $action,
        ?string $actorUserId = null,
        $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        $occurredAt = null,
    ) use (&$observed, $realWriter): AuditLog {
        $observed['in_transaction'] = DB::transactionLevel() > 0;

        return $realWriter->write(
            $action,
            $actorUserId,
            $auditable,
            $oldValues,
            array_merge($newValues ?? [], ['password' => 'should-be-stripped', 'token' => 'should-be-stripped']),
            array_merge($metadata ?? [], ['Authorization' => 'Bearer should-be-stripped']),
            $occurredAt,
        );
    });
    app()->instance(AuditLogWriter::class, $writer);

    $inbox = app(CreateInboxAction::class)->execute($data, $ctx['user'], $context);
    $audit = AuditLog::query()->where('action', 'inbox.created')->sole();

    expect($observed['in_transaction'])->toBeTrue()
        ->and($audit->new_values)->toHaveKey('is_active')
        ->and($audit->new_values)->not->toHaveKey('password')
        ->and($audit->new_values)->not->toHaveKey('token')
        ->and($audit->metadata)->toHaveKey('api_key_id')
        ->and($audit->metadata)->not->toHaveKey('Authorization')
        ->and(json_encode([$audit->old_values, $audit->new_values, $audit->metadata]))->not->toContain('should-be-stripped');

    assertLifecycleAuditPayloadIsSafe($audit, $inbox, $ctx['mailServer']);
});
