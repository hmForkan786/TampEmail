<?php

declare(strict_types=1);

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Contracts\OutboundTransportInterface;
use App\DTOs\Outbound\OutboundDeliveryResult;
use App\Enums\BillingCycle;
use App\Enums\OutboundMessageState;
use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Feature;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\FakeOutboundTransport;
use App\Services\Outbound\OutboundAttachmentSelector;
use App\Services\Outbound\OutboundAuthorizationService;
use App\Services\Outbound\OutboundSuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'api.key_hash_secret' => 'outbound-send-test-secret',
        'outbound.enabled' => true,
        'outbound.send_enabled' => true,
        'outbound.transport' => 'unavailable',
        'queue.default' => 'sync',
    ]);
});

/**
 * @return array{user: User, domain: Domain, inbox: Inbox, token: string, transport: FakeOutboundTransport}
 */
function outboundSendContext(array $overrides = []): array
{
    $user = User::factory()->create();
    $plan = Plan::query()->create([
        'slug' => 'outbound-'.uniqid(),
        'name' => 'Outbound Plan',
        'price_monthly' => '0.00',
        'price_yearly' => '0.00',
        'currency' => 'USD',
        'is_free' => true,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $feature = Feature::query()->firstOrCreate(
        ['key' => 'send_email'],
        [
            'name' => 'Send Email',
            'value_type' => ValueType::Boolean,
            'default_value' => ['enabled' => true],
            'is_active' => true,
            'display_order' => 10,
        ],
    );
    $plan->features()->syncWithoutDetaching([
        $feature->id => ['feature_value' => ['enabled' => true]],
    ]);

    Subscription::query()->create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly,
        'starts_at' => now()->subDay(),
        'auto_renew' => true,
        'price' => '0.00',
        'currency' => 'USD',
    ]);

    $domain = Domain::query()->create([
        'domain' => 'out-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Outbound',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'outbound_enabled' => true,
        'retention_hours' => 24,
    ]);

    $inbox = Inbox::query()->create(array_merge([
        'domain_id' => $domain->id,
        'user_id' => $user->id,
        'local_part' => 'sender',
        'full_address' => 'sender@'.$domain->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
    ], $overrides['inbox'] ?? []));

    $token = app(CreateApiKeyAction::class)->issue(
        userId: $user->id,
        name: 'outbound-key',
        permissions: $overrides['scopes'] ?? ['outbound_messages:read', 'outbound_messages:write'],
        user: $user,
    )->plainToken;

    $transport = new FakeOutboundTransport(OutboundDeliveryResult::accepted('fake', 'fake-msg-1'));
    app()->instance(OutboundTransportInterface::class, $transport);

    return compact('user', 'domain', 'inbox', 'token', 'transport');
}

function outboundPayload(array $ctx, array $overrides = []): array
{
    return array_merge([
        'inbox_id' => $ctx['inbox']->id,
        'idempotency_key' => 'idem-'.bin2hex(random_bytes(4)),
        'to' => ['recipient@example.test'],
        'subject' => 'Hello outbound',
        'text_body' => 'Plain body',
    ], $overrides);
}

it('sends an authorized outbound message through the queued transport', function (): void {
    Queue::fake();
    $ctx = outboundSendContext();

    $response = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundPayload($ctx, [
            'cc' => ['cc@example.test'],
            'bcc' => ['bcc@example.test'],
            'html_body' => '<p>Hello <script>alert(1)</script></p>',
        ]));

    $response->assertCreated()
        ->assertJsonPath('data.state', 'queued')
        ->assertJsonPath('data.operation', 'send')
        ->assertJsonPath('data.from.email', $ctx['inbox']->full_address)
        ->assertJsonPath('data.to.0', 'recipient@example.test')
        ->assertJsonPath('data.bcc.0', 'bcc@example.test')
        ->assertJsonMissingPath('data.provider_credentials');

    expect($response->json('data.html_body'))->not->toContain('<script>');

    $message = OutboundMessage::query()->first();
    expect($message)->not->toBeNull()
        ->and($message->state)->toBe(OutboundMessageState::Queued)
        ->and($message->from_address)->toBe($ctx['inbox']->full_address);

    Queue::assertPushed(DeliverOutboundMessageJob::class, fn (DeliverOutboundMessageJob $job): bool => $job->outboundMessageId === $message->id);

    expect(AuditLog::query()->where('action', 'outbound.message_created')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'outbound.message_queued')->exists())->toBeTrue();
});

it('delivers via atomic claim and records sent state', function (): void {
    $ctx = outboundSendContext();
    Queue::fake();

    $created = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundPayload($ctx))
        ->assertCreated()
        ->json('data.id');

    (new DeliverOutboundMessageJob($created))->handle(
        $ctx['transport'],
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
        app(OutboundSuppressionService::class),
    );

    $message = OutboundMessage::query()->findOrFail($created);
    expect($message->state)->toBe(OutboundMessageState::Sent)
        ->and($message->attempt_count)->toBe(1)
        ->and($message->provider)->toBe('fake')
        ->and($ctx['transport']->sent)->toHaveCount(1);

    expect(AuditLog::query()->where('action', 'outbound.message_sent')->exists())->toBeTrue();

    // Duplicate job must not double-send.
    (new DeliverOutboundMessageJob($created))->handle(
        $ctx['transport'],
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
        app(OutboundSuppressionService::class),
    );
    expect($ctx['transport']->sent)->toHaveCount(1)
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Sent);
});

it('rejects unauthenticated and missing scope requests', function (): void {
    $ctx = outboundSendContext(['scopes' => ['outbound_messages:read']]);

    $this->postJson('/api/v1/outbound-messages', outboundPayload($ctx))->assertUnauthorized();

    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundPayload($ctx))
        ->assertForbidden();
});

it('denies foreign inactive domain and entitlement failures', function (): void {
    $ctx = outboundSendContext();
    $other = User::factory()->create();
    $otherToken = app(CreateApiKeyAction::class)->issue(
        userId: $other->id,
        name: 'other',
        permissions: ['outbound_messages:write'],
        user: $other,
    )->plainToken;

    $this->withToken($otherToken)
        ->postJson('/api/v1/outbound-messages', outboundPayload($ctx))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'inbox_forbidden');

    $ctx['inbox']->update(['is_active' => false]);
    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundPayload($ctx, ['idempotency_key' => 'k-inactive']))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'inbox_inactive');

    $ctx['inbox']->update(['is_active' => true]);
    $ctx['domain']->update(['outbound_enabled' => false]);
    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundPayload($ctx, ['idempotency_key' => 'k-domain']))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'domain_outbound_disabled');

    $ctx['domain']->update(['outbound_enabled' => true]);
    config(['outbound.send_enabled' => false]);
    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundPayload($ctx, ['idempotency_key' => 'k-flag']))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'operation_disabled');

    config(['outbound.send_enabled' => true]);
    Subscription::query()->where('user_id', $ctx['user']->id)->delete();
    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundPayload($ctx, ['idempotency_key' => 'k-ent']))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'entitlement_denied');
});

it('validates recipients content and blocks arbitrary sender', function (): void {
    $ctx = outboundSendContext();

    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundPayload($ctx, [
            'idempotency_key' => 'bad-to',
            'to' => ['not-an-email'],
        ]))->assertStatus(422);

    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundPayload($ctx, [
            'idempotency_key' => 'inject',
            'subject' => "Hello\r\nBcc: evil@example.test",
        ]))->assertStatus(422)->assertJsonPath('error.code', 'header_injection');

    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundPayload($ctx, [
            'idempotency_key' => 'from-block',
            'from' => 'attacker@evil.test',
        ]))->assertStatus(422);

    $dup = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundPayload($ctx, [
            'idempotency_key' => 'dedupe',
            'to' => ['A@Example.TEST', 'a@example.test'],
            'cc' => ['a@example.test'],
        ]));
    $dup->assertCreated();
    expect($dup->json('data.to'))->toBe(['a@example.test'])
        ->and($dup->json('data.cc'))->toBe([]);
});

it('supports idempotency replay and conflict', function (): void {
    Queue::fake();
    $ctx = outboundSendContext();
    $payload = outboundPayload($ctx, ['idempotency_key' => 'same-key']);

    $first = $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', $payload)->assertCreated();
    $second = $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', $payload)->assertCreated();
    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(OutboundMessage::query()->count())->toBe(1);

    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', array_merge($payload, ['subject' => 'Different']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_conflict');
});

it('handles temporary and permanent transport failures and unavailable config', function (): void {
    $ctx = outboundSendContext();
    Queue::fake();

    $id = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundPayload($ctx, ['idempotency_key' => 'temp']))
        ->json('data.id');

    $ctx['transport']->setNextResult(OutboundDeliveryResult::temporaryFailure('smtp_4xx', 'Temp'));
    config(['outbound.send_max_attempts' => 3]);

    try {
        (new DeliverOutboundMessageJob($id))->handle(
            $ctx['transport'],
            app(OutboundAuthorizationService::class),
            app(AuditLogWriter::class),
            app(OutboundAttachmentSelector::class),
            app(OutboundSuppressionService::class),
        );
        expect(false)->toBeTrue();
    } catch (RuntimeException) {
        expect(OutboundMessage::query()->find($id)->state)->toBe(OutboundMessageState::Queued)
            ->and(OutboundMessage::query()->find($id)->attempt_count)->toBe(1);
    }

    $id2 = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundPayload($ctx, ['idempotency_key' => 'perm']))
        ->json('data.id');
    $ctx['transport']->setNextResult(OutboundDeliveryResult::permanentFailure('smtp_5xx', 'Nope'));
    (new DeliverOutboundMessageJob($id2))->handle(
        $ctx['transport'],
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
        app(OutboundSuppressionService::class),
    );
    expect(OutboundMessage::query()->find($id2)->state)->toBe(OutboundMessageState::Failed)
        ->and(AuditLog::query()->where('action', 'outbound.message_failed')->exists())->toBeTrue();

    app()->forgetInstance(OutboundTransportInterface::class);
    config(['outbound.transport' => 'unavailable']);
    $unavailable = app(OutboundTransportInterface::class);
    $id3 = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundPayload($ctx, ['idempotency_key' => 'unavail']))
        ->json('data.id');
    (new DeliverOutboundMessageJob($id3))->handle(
        $unavailable,
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
        app(OutboundSuppressionService::class),
    );
    expect(OutboundMessage::query()->find($id3)->failure_code)->toBe('transport_unavailable');
});

it('shows owned outbound message status and hides foreign messages', function (): void {
    Queue::fake();
    $ctx = outboundSendContext();
    $id = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundPayload($ctx, [
            'idempotency_key' => 'show',
            'bcc' => ['hidden@example.test'],
        ]))->json('data.id');

    $show = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-messages/'.$id);
    $show->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.bcc.0', 'hidden@example.test');

    $other = User::factory()->create();
    $otherToken = app(CreateApiKeyAction::class)->issue(
        userId: $other->id,
        name: 'reader',
        permissions: ['outbound_messages:read'],
        user: $other,
    )->plainToken;

    $this->withToken($otherToken)->getJson('/api/v1/outbound-messages/'.$id)->assertNotFound();
});
