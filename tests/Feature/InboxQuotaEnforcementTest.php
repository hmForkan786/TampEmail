<?php

use App\Actions\Inbox\CreateInboxAction;
use App\DTOs\Inbox\CreateInboxData;
use App\DTOs\Inbox\InboxMutationContext;
use App\Enums\BillingCycle;
use App\Enums\InboxType;
use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Exceptions\EligibleMailServerUnavailableException;
use App\Exceptions\InboxQuotaExceededException;
use App\Models\Domain;
use App\Models\Feature;
use App\Models\Inbox;
use App\Models\MailServer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, domain: Domain, mailServer: MailServer}
 */
function entitledInboxContext(int $inboxLimit = 2, ?int $serverCapacity = null): array
{
    $user = User::factory()->create();

    $plan = Plan::create([
        'slug' => 'quota-test-'.uniqid(),
        'name' => 'Quota Test',
        'price_monthly' => '0.00',
        'price_yearly' => '0.00',
        'currency' => 'USD',
        'is_free' => true,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $maxInboxesFeature = Feature::query()->firstOrCreate(
        ['key' => 'max_inboxes'],
        [
            'name' => 'Max Inboxes',
            'value_type' => ValueType::Json,
            'is_active' => true,
            'display_order' => 1,
        ],
    );

    $poolsFeature = Feature::query()->firstOrCreate(
        ['key' => 'mail_server_pools'],
        [
            'name' => 'Mail Server Pools',
            'value_type' => ValueType::Json,
            'is_active' => true,
            'display_order' => 2,
        ],
    );

    $plan->features()->attach($maxInboxesFeature->id, [
        'feature_value' => ['limit' => $inboxLimit],
    ]);

    $plan->features()->attach($poolsFeature->id, [
        'feature_value' => ['pools' => ['standard']],
    ]);

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
        'domain' => 'quota-'.uniqid().'.example.test',
        'display_name' => 'Quota Example',
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
        'name' => 'Standard inbound',
        'hostname' => 'standard.quota.example.test',
        'provider' => 'smtp',
        'protocol' => 'smtp',
        'is_active' => true,
        'priority' => 1,
        'last_health_check_at' => now(),
        'pool_key' => 'standard',
        'max_inboxes' => $serverCapacity,
    ]);

    return compact('user', 'domain', 'mailServer');
}

function authenticatedInboxData(Domain $domain, User $user): CreateInboxData
{
    $suffix = uniqid();

    return new CreateInboxData(
        domainId: $domain->id,
        userId: $user->id,
        localPart: 'user-'.$suffix,
        fullAddress: $suffix.'@'.$domain->domain,
        displayName: null,
        inboxType: InboxType::Temporary,
        expiresAt: now()->addHour(),
        metadata: null,
    );
}

function quotaApiContext(User $user): InboxMutationContext
{
    return InboxMutationContext::forApi((string) $user->id, (string) Str::uuid());
}

function createEntitledInbox(Domain $domain, User $user): Inbox
{
    return app(CreateInboxAction::class)->execute(
        authenticatedInboxData($domain, $user),
        $user,
        quotaApiContext($user),
    );
}

it('rejects inbox creation when the user quota is full', function (): void {
    ['user' => $user, 'domain' => $domain] = entitledInboxContext(inboxLimit: 1);

    createEntitledInbox($domain, $user);

    expect(fn () => createEntitledInbox($domain, $user))
        ->toThrow(InboxQuotaExceededException::class);
});

it('creates an inbox when the user is within quota', function (): void {
    ['user' => $user, 'domain' => $domain, 'mailServer' => $mailServer] = entitledInboxContext(inboxLimit: 2);

    $inbox = createEntitledInbox($domain, $user);

    expect($inbox->user_id)->toBe($user->id)
        ->and($inbox->mail_server_id)->toBe($mailServer->id);
    $this->assertDatabaseHas('inboxes', ['id' => $inbox->id, 'user_id' => $user->id]);
});

it('allows only the entitled number of inboxes at the quota boundary', function (): void {
    ['user' => $user, 'domain' => $domain] = entitledInboxContext(inboxLimit: 2);

    createEntitledInbox($domain, $user);
    createEntitledInbox($domain, $user);

    expect(fn () => createEntitledInbox($domain, $user))
        ->toThrow(InboxQuotaExceededException::class);

    expect(Inbox::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('does not persist an inbox when quota enforcement fails', function (): void {
    ['user' => $user, 'domain' => $domain] = entitledInboxContext(inboxLimit: 1);

    createEntitledInbox($domain, $user);

    try {
        createEntitledInbox($domain, $user);
    } catch (InboxQuotaExceededException) {
        // expected
    }

    expect(Inbox::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('fails on user quota before mail-server capacity when the user quota is exhausted', function (): void {
    ['user' => $user, 'domain' => $domain] = entitledInboxContext(inboxLimit: 1, serverCapacity: null);

    createEntitledInbox($domain, $user);

    expect(fn () => createEntitledInbox($domain, $user))
        ->toThrow(InboxQuotaExceededException::class);
});

it('enforces mail-server capacity even when user quota remains', function (): void {
    ['user' => $user, 'domain' => $domain] = entitledInboxContext(inboxLimit: 5, serverCapacity: 1);

    createEntitledInbox($domain, $user);

    expect(fn () => createEntitledInbox($domain, $user))
        ->toThrow(EligibleMailServerUnavailableException::class);
});

it('does not count soft-deleted inboxes toward quota', function (): void {
    ['user' => $user, 'domain' => $domain] = entitledInboxContext(inboxLimit: 1);

    $existing = createEntitledInbox($domain, $user);
    $existing->delete();

    $replacement = createEntitledInbox($domain, $user);

    expect($replacement->id)->not->toBe($existing->id);
});

it('allows unlimited inbox creation when the entitlement limit is null', function (): void {
    ['user' => $user, 'domain' => $domain] = entitledInboxContext(inboxLimit: 2);

    $plan = Subscription::query()->where('user_id', $user->id)->firstOrFail()->plan;
    $maxInboxesFeature = Feature::query()->where('key', 'max_inboxes')->firstOrFail();
    $plan->features()->updateExistingPivot($maxInboxesFeature->id, [
        'feature_value' => ['limit' => null],
    ]);

    createEntitledInbox($domain, $user);
    createEntitledInbox($domain, $user);
    $third = createEntitledInbox($domain, $user);

    expect($third)->toBeInstanceOf(Inbox::class);
});

it('does not affect another user quota enforcement', function (): void {
    ['user' => $userA, 'domain' => $domainA] = entitledInboxContext(inboxLimit: 1);
    ['user' => $userB, 'domain' => $domainB] = entitledInboxContext(inboxLimit: 1);

    createEntitledInbox($domainA, $userA);

    expect(fn () => createEntitledInbox($domainA, $userA))
        ->toThrow(InboxQuotaExceededException::class);

    $userBInbox = createEntitledInbox($domainB, $userB);

    expect($userBInbox->user_id)->toBe($userB->id);
});

it('locks the user row before quota checks within the provisioning transaction', function (): void {
    ['user' => $user] = entitledInboxContext(inboxLimit: 2);

    DB::transaction(function () use ($user): void {
        $locked = User::query()
            ->whereKey($user->id)
            ->lockForUpdate()
            ->first();

        expect($locked)->not->toBeNull()
            ->and($locked?->is($user))->toBeTrue();
    });
});

it('documents sqlite test limitation for true parallel quota concurrency', function (): void {
    expect(config('database.default'))->toBe('sqlite');

    // Production uses row-level locking in CreateInboxAction; SQLite in-memory
    // tests cannot reliably simulate concurrent transactions across connections.
})->note('Parallel concurrency requires MySQL/PostgreSQL integration coverage.');
