<?php

use App\Actions\Inbox\CreateInboxAction;
use App\Actions\Inbox\DeleteInboxAction;
use App\Actions\Inbox\RenewInboxAction;
use App\DTOs\Inbox\CreateInboxData;
use App\DTOs\Inbox\InboxMutationContext;
use App\Enums\BillingCycle;
use App\Enums\InboxType;
use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Feature;
use App\Models\Inbox;
use App\Models\MailServer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Inbox\ExpireInboxesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, domain: Domain, mailServer: MailServer}
 */
function mutationContextFixture(): array
{
    $user = User::factory()->create();
    $plan = Plan::create([
        'slug' => 'ctx-'.uniqid(), 'name' => 'Ctx', 'price_monthly' => '0.00', 'price_yearly' => '0.00',
        'currency' => 'USD', 'is_free' => true, 'is_active' => true, 'display_order' => 1,
    ]);
    $max = Feature::query()->firstOrCreate(['key' => 'max_inboxes'], ['name' => 'Max', 'value_type' => ValueType::Json, 'is_active' => true, 'display_order' => 1]);
    $pools = Feature::query()->firstOrCreate(['key' => 'mail_server_pools'], ['name' => 'Pools', 'value_type' => ValueType::Json, 'is_active' => true, 'display_order' => 2]);
    $plan->features()->attach($max->id, ['feature_value' => ['limit' => 10]]);
    $plan->features()->attach($pools->id, ['feature_value' => ['pools' => ['standard']]]);
    Subscription::create([
        'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly, 'starts_at' => now()->subDay(), 'auto_renew' => true,
        'price' => '0.00', 'currency' => 'USD',
    ]);
    $domain = Domain::create([
        'domain' => 'ctx-'.uniqid().'.test', 'display_name' => 'Ctx', 'is_active' => true, 'is_public' => true,
        'allow_registration' => true, 'is_healthy' => true, 'priority' => 1, 'retention_hours' => 24,
    ]);
    $mailServer = MailServer::create([
        'name' => 'Ctx MX', 'hostname' => 'ctx-'.uniqid().'.test', 'provider' => 'smtp', 'protocol' => 'smtp',
        'is_active' => true, 'priority' => 1, 'last_health_check_at' => now(), 'pool_key' => 'standard',
    ]);

    return compact('user', 'domain', 'mailServer');
}

function mutationCreateData(Domain $domain, ?User $user): CreateInboxData
{
    $suffix = uniqid();

    return new CreateInboxData(
        domainId: $domain->id,
        userId: $user?->id,
        localPart: 'ctx-'.$suffix,
        fullAddress: $suffix.'@'.$domain->domain,
        displayName: null,
        inboxType: InboxType::Temporary,
        expiresAt: now()->addHour(),
        metadata: null,
    );
}

it('rejects create delete and renew without using factories for invalid sources and missing api key', function (): void {
    expect(fn () => InboxMutationContext::forApi('', (string) Str::uuid()))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => InboxMutationContext::forApi((string) Str::uuid(), ''))
        ->toThrow(InvalidArgumentException::class);
    expect(function (): void {
        $ref = new ReflectionClass(InboxMutationContext::class);
        $ctor = $ref->getConstructor();
        expect($ctor)->not->toBeNull();
        $ctor->invoke($ref->newInstanceWithoutConstructor(), null, 'filament', null);
    })->toThrow(InvalidArgumentException::class);
});

it('rejects create without a required context argument at the call site', function (): void {
    ['user' => $user, 'domain' => $domain] = mutationContextFixture();
    $method = new ReflectionMethod(CreateInboxAction::class, 'execute');
    expect($method->getNumberOfRequiredParameters())->toBe(3)
        ->and($method->getParameters()[2]->getName())->toBe('context')
        ->and($method->getParameters()[2]->allowsNull())->toBeFalse();

    $delete = new ReflectionMethod(DeleteInboxAction::class, 'execute');
    expect($delete->getNumberOfRequiredParameters())->toBe(2)
        ->and($delete->getParameters()[1]->allowsNull())->toBeFalse();

    expect(fn () => app(CreateInboxAction::class)->execute(mutationCreateData($domain, $user), $user))
        ->toThrow(ArgumentCountError::class);
    expect(fn () => app(DeleteInboxAction::class)->execute(Inbox::make(['id' => (string) Str::uuid()])))
        ->toThrow(ArgumentCountError::class);
});

it('accepts a valid API context and writes exactly one audit on create', function (): void {
    ['user' => $user, 'domain' => $domain] = mutationContextFixture();
    $keyId = (string) Str::uuid();
    $inbox = app(CreateInboxAction::class)->execute(
        mutationCreateData($domain, $user),
        $user,
        InboxMutationContext::forApi((string) $user->id, $keyId),
    );

    $audit = AuditLog::query()->where('action', 'inbox.created')->sole();
    expect($inbox->user_id)->toBe((string) $user->id)
        ->and($audit->user_id)->toBe((string) $user->id)
        ->and($audit->metadata['source'])->toBe('api')
        ->and($audit->metadata['api_key_id'])->toBe($keyId)
        ->and(AuditLog::query()->count())->toBe(1);
});

it('rejects actor-owner and payload-owner mismatches without persistence', function (): void {
    ['user' => $user, 'domain' => $domain] = mutationContextFixture();
    $other = User::factory()->create();

    expect(fn () => app(CreateInboxAction::class)->execute(
        mutationCreateData($domain, $user),
        $user,
        InboxMutationContext::forApi((string) $other->id, (string) Str::uuid()),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => app(CreateInboxAction::class)->execute(
        mutationCreateData($domain, $other),
        $user,
        InboxMutationContext::forApi((string) $user->id, (string) Str::uuid()),
    ))->toThrow(InvalidArgumentException::class);

    expect(Inbox::query()->count())->toBe(0)->and(AuditLog::query()->count())->toBe(0);
});

it('accepts anonymous creation and rejects anonymous context for user-owned mutation', function (): void {
    config(['inbox.public_mail_server_pool' => 'public']);
    ['domain' => $domain] = mutationContextFixture();
    MailServer::query()->where('pool_key', 'standard')->update(['pool_key' => 'public']);

    $inbox = app(CreateInboxAction::class)->execute(
        mutationCreateData($domain, null),
        null,
        InboxMutationContext::forAnonymous(),
    );
    expect($inbox->user_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'inbox.created')->sole()->metadata['source'])->toBe('anonymous')
        ->and(AuditLog::query()->where('action', 'inbox.created')->sole()->user_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'inbox.created')->sole()->metadata['api_key_id'])->toBeNull();

    ['user' => $user, 'domain' => $ownedDomain] = mutationContextFixture();
    expect(fn () => app(CreateInboxAction::class)->execute(
        mutationCreateData($ownedDomain, $user),
        $user,
        InboxMutationContext::forAnonymous(),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => app(DeleteInboxAction::class)->execute($inbox, InboxMutationContext::forAnonymous()))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects scheduler context outside expiration and accepts it for expiration', function (): void {
    ['user' => $user, 'domain' => $domain] = mutationContextFixture();

    expect(fn () => app(CreateInboxAction::class)->execute(
        mutationCreateData($domain, $user),
        $user,
        InboxMutationContext::forScheduler(),
    ))->toThrow(InvalidArgumentException::class);

    $owned = app(CreateInboxAction::class)->execute(
        mutationCreateData($domain, $user),
        $user,
        InboxMutationContext::forApi((string) $user->id, (string) Str::uuid()),
    );
    expect(fn () => app(DeleteInboxAction::class)->execute($owned, InboxMutationContext::forScheduler()))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(RenewInboxAction::class)->execute(
        $owned,
        now()->addHours(2),
        $user,
        InboxMutationContext::forScheduler(),
    ))->toThrow(InvalidArgumentException::class);

    $owned->forceFill(['expires_at' => now()->subMinute()])->save();
    $result = app(ExpireInboxesService::class)->process(true, 10);
    expect($result['processed'])->toBeGreaterThanOrEqual(1)
        ->and(AuditLog::query()->where('action', 'inbox.expired')->sole()->metadata['source'])->toBe('scheduler')
        ->and(AuditLog::query()->where('action', 'inbox.expired')->sole()->user_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'inbox.expired')->sole()->metadata)->toHaveKey('api_key_id');
});

it('rejects renew with anonymous context and actor mismatch without writing audit', function (): void {
    ['user' => $user, 'domain' => $domain] = mutationContextFixture();
    $other = User::factory()->create();
    $inbox = app(CreateInboxAction::class)->execute(
        mutationCreateData($domain, $user),
        $user,
        InboxMutationContext::forApi((string) $user->id, (string) Str::uuid()),
    );
    AuditLog::query()->delete();

    expect(fn () => app(RenewInboxAction::class)->execute(
        $inbox,
        now()->addHours(2),
        $user,
        InboxMutationContext::forAnonymous(),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => app(RenewInboxAction::class)->execute(
        $inbox,
        now()->addHours(2),
        $user,
        InboxMutationContext::forApi((string) $other->id, (string) Str::uuid()),
    ))->toThrow(InvalidArgumentException::class);

    expect(AuditLog::query()->where('action', 'inbox.expiration_extended')->count())->toBe(0)
        ->and($inbox->fresh()->expires_at->equalTo($inbox->expires_at))->toBeTrue();
});

it('writes exactly one deactivate audit for a valid API delete context', function (): void {
    ['user' => $user, 'domain' => $domain] = mutationContextFixture();
    $keyId = (string) Str::uuid();
    $inbox = app(CreateInboxAction::class)->execute(
        mutationCreateData($domain, $user),
        $user,
        InboxMutationContext::forApi((string) $user->id, $keyId),
    );
    AuditLog::query()->delete();

    expect(app(DeleteInboxAction::class)->execute($inbox, InboxMutationContext::forApi((string) $user->id, $keyId)))->toBeTrue();
    $audit = AuditLog::query()->where('action', 'inbox.deactivated')->sole();
    expect($audit->metadata['source'])->toBe('api')
        ->and($audit->metadata['api_key_id'])->toBe($keyId)
        ->and(AuditLog::query()->count())->toBe(1);
});
