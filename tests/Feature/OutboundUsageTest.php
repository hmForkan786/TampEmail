<?php

declare(strict_types=1);

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Actions\Outbound\CancelOutboundMessageAction;
use App\Actions\Outbound\DeleteOutboundMessageAction;
use App\Contracts\OutboundTransportInterface;
use App\DTOs\Outbound\OutboundDeliveryResult;
use App\Enums\AttachmentScanStatus;
use App\Enums\BillingCycle;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundUsageReservationState;
use App\Enums\PlatformRole;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Enums\ValueType;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Email;
use App\Models\EmailBody;
use App\Models\Feature;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\OutboundUsageReservation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionUsage;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\FakeOutboundTransport;
use App\Services\Outbound\OutboundAttachmentSelector;
use App\Services\Outbound\OutboundAuthorizationService;
use App\Services\Outbound\OutboundDeliveryAttemptRecorder;
use App\Services\Outbound\OutboundLaunchControlService;
use App\Services\Outbound\OutboundSuppressionService;
use App\Services\Outbound\OutboundUsageReconciliationService;
use App\Services\Outbound\OutboundUsageService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'api.key_hash_secret' => 'outbound-usage-test-secret',
        'outbound.enabled' => true,
        'outbound.send_enabled' => true,
        'outbound.reply_enabled' => true,
        'outbound.forward_enabled' => true,
        'outbound.transport' => 'unavailable',
        'outbound.rollout.mode' => 'enabled',
        'outbound.rollout.emergency_stop' => false,
        'outbound_usage.metering_enabled' => true,
        'filesystems.disks.attachments.visibility' => 'private',
        'queue.default' => 'sync',
    ]);
    Storage::fake('attachments');
});

/**
 * @return array{user: User, plan: Plan, subscription: Subscription, domain: Domain, inbox: Inbox, token: string, transport: FakeOutboundTransport}
 */
function outboundUsageContext(array $overrides = []): array
{
    $user = User::factory()->create();
    $plan = Plan::query()->create([
        'slug' => 'outbound-usage-'.uniqid(),
        'name' => 'Outbound Usage Plan',
        'price_monthly' => '0.00',
        'price_yearly' => '0.00',
        'currency' => 'USD',
        'is_free' => true,
        'is_active' => true,
        'display_order' => 1,
    ]);

    foreach (['send_email', 'reply_email', 'forward_email'] as $key) {
        $feature = Feature::query()->firstOrCreate(
            ['key' => $key],
            [
                'name' => ucfirst(str_replace('_', ' ', $key)),
                'value_type' => ValueType::Boolean,
                'default_value' => ['enabled' => true],
                'is_active' => true,
                'display_order' => 10,
            ],
        );
        $plan->features()->syncWithoutDetaching([
            $feature->id => ['feature_value' => ['enabled' => true]],
        ]);
    }

    $subscription = Subscription::query()->create([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'status' => $overrides['subscription_status'] ?? SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly,
        'starts_at' => now()->subDay(),
        'auto_renew' => true,
        'price' => '0.00',
        'currency' => 'USD',
    ]);

    $domain = Domain::query()->create([
        'domain' => 'usage-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Outbound Usage',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'outbound_enabled' => true,
        'retention_hours' => 24,
    ]);

    $inbox = Inbox::query()->create([
        'domain_id' => $domain->id,
        'user_id' => $user->id,
        'local_part' => 'sender',
        'full_address' => 'sender@'.$domain->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
    ]);

    $token = app(CreateApiKeyAction::class)->issue(
        userId: $user->id,
        name: 'outbound-usage-key',
        permissions: $overrides['scopes'] ?? ['outbound_messages:read', 'outbound_messages:write'],
        user: $user,
    )->plainToken;

    $transport = new FakeOutboundTransport(OutboundDeliveryResult::accepted('fake', 'fake-msg-1'));
    app()->instance(OutboundTransportInterface::class, $transport);

    return compact('user', 'plan', 'subscription', 'domain', 'inbox', 'token', 'transport');
}

function outboundUsagePayload(array $ctx, array $overrides = []): array
{
    return array_merge([
        'inbox_id' => $ctx['inbox']->id,
        'idempotency_key' => 'usage-'.bin2hex(random_bytes(4)),
        'to' => ['recipient@example.test'],
        'subject' => 'Hello usage',
        'text_body' => 'Plain body',
    ], $overrides);
}

function attachMeteredFeature(Plan $plan, string $key, ?int $limit, string $resetPeriod = 'monthly'): Feature
{
    $feature = Feature::query()->firstOrCreate(
        ['key' => $key],
        [
            'name' => ucfirst(str_replace('_', ' ', $key)),
            'value_type' => ValueType::Json,
            'default_value' => ['limit' => null, 'reset_period' => 'monthly'],
            'is_active' => true,
            'display_order' => 20,
        ],
    );

    $plan->features()->syncWithoutDetaching([
        $feature->id => ['feature_value' => ['limit' => $limit, 'reset_period' => $resetPeriod]],
    ]);

    return $feature;
}

function deliverOutboundUsageMessage(string $id, FakeOutboundTransport $transport): void
{
    (new DeliverOutboundMessageJob($id))->handle(
        $transport,
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
        app(OutboundSuppressionService::class),
        app(OutboundDeliveryAttemptRecorder::class),
        app(OutboundLaunchControlService::class),
        app(OutboundUsageService::class),
    );
}

function outboundUsagePlatformAdmin(): User
{
    return User::factory()->create([
        'platform_role' => PlatformRole::Admin,
        'status' => UserStatus::Active,
    ]);
}

// -----------------------------------------------------------------------
// Feature entitlement / unlimited-by-default behaviour
// -----------------------------------------------------------------------

it('fails closed when the required outbound message quota mapping is missing', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();

    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'plan_limit_reached');

    expect(OutboundMessage::query()->count())->toBe(0)
        ->and(OutboundUsageReservation::query()->count())->toBe(0)
        ->and(SubscriptionUsage::query()->count())->toBe(0);
});

it('does not meter usage at all when metering is disabled', function (): void {
    Queue::fake();
    config(['outbound_usage.metering_enabled' => false]);
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 1);

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))->assertCreated();
    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))->assertCreated();

    expect(OutboundMessage::query()->count())->toBe(2)
        ->and(OutboundUsageReservation::query()->count())->toBe(0);
});

// -----------------------------------------------------------------------
// Allowance enforcement (messages / recipients / attachment bytes)
// -----------------------------------------------------------------------

it('enforces the per-period message allowance and rolls back the message on exceed', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 2);

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))->assertCreated();
    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))->assertCreated();

    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'outbound_quota_messages_exceeded');

    expect(OutboundMessage::query()->count())->toBe(2)
        ->and(OutboundUsageReservation::query()->count())->toBe(2);
});

it('enforces the per-period recipient allowance', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 10);
    attachMeteredFeature($ctx['plan'], 'outbound_recipients_per_period', 2);

    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx, ['to' => ['a@example.test', 'b@example.test']]))
        ->assertCreated();

    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx, ['to' => ['c@example.test']]))
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'outbound_quota_recipients_exceeded');

    expect(OutboundMessage::query()->count())->toBe(1);
});

it('enforces the per-period attachment byte allowance on forwards', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 10);
    attachMeteredFeature($ctx['plan'], 'outbound_attachment_bytes_per_period', 10);

    ['email' => $email, 'attachment' => $attachment] = createOutboundUsageForwardableEmail($ctx, 50);

    $response = $this->withToken($ctx['token'])
        ->postJson('/api/v1/emails/'.$email->id.'/forward', [
            'idempotency_key' => 'fwd-'.bin2hex(random_bytes(4)),
            'to' => ['dest@example.test'],
            'attachment_ids' => [$attachment->id],
        ]);

    $response->assertStatus(429)->assertJsonPath('error.code', 'outbound_quota_attachment_bytes_exceeded');
    expect(OutboundMessage::query()->count())->toBe(0);
});

it('fails closed when the required outbound message quota limit is null', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', null);

    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'plan_limit_reached');

    expect(OutboundMessage::query()->count())->toBe(0);
});

// -----------------------------------------------------------------------
// No subscription / expired subscription
// -----------------------------------------------------------------------

it('denies outbound send with no active subscription before usage is ever reserved', function (): void {
    $ctx = outboundUsageContext();
    Subscription::query()->where('user_id', $ctx['user']->id)->delete();

    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'feature_not_available');

    expect(OutboundUsageReservation::query()->count())->toBe(0);
});

it('denies outbound send once the subscription has expired', function (): void {
    $ctx = outboundUsageContext();
    $ctx['subscription']->update(['status' => SubscriptionStatus::Expired]);

    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'feature_not_available');
});

// -----------------------------------------------------------------------
// Idempotency / conflict / concurrency
// -----------------------------------------------------------------------

it('does not double reserve on idempotent replay', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 5);
    $payload = outboundUsagePayload($ctx, ['idempotency_key' => 'replay-key']);

    $first = $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', $payload)->assertCreated();
    $second = $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', $payload)->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(OutboundMessage::query()->count())->toBe(1)
        ->and(OutboundUsageReservation::query()->count())->toBe(1);
});

it('does not reserve usage on an idempotency conflict', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 5);
    $payload = outboundUsagePayload($ctx, ['idempotency_key' => 'conflict-key']);

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', $payload)->assertCreated();

    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', array_merge($payload, ['subject' => 'Different']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_conflict');

    expect(OutboundUsageReservation::query()->count())->toBe(1);
});

it('protects against exceeding allowance via outstanding reservations before any commit', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 1);

    // First message reserves the only unit but is never delivered/committed.
    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))->assertCreated();

    // A second reservation must be rejected because the outstanding
    // reserved unit already accounts for the full allowance, even though
    // used_value is still zero.
    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'outbound_quota_messages_exceeded');

    expect(OutboundMessage::query()->count())->toBe(1);
});

it('releases the reservation without incrementing used_value when the creation transaction rolls back', function (): void {
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 5);

    $message = OutboundMessage::query()->create([
        'user_id' => $ctx['user']->id,
        'inbox_id' => $ctx['inbox']->id,
        'operation' => 'send',
        'idempotency_key' => 'rollback-key',
        'request_fingerprint' => hash('sha256', 'rollback-key'),
        'state' => OutboundMessageState::Queued->value,
        'from_address' => $ctx['inbox']->full_address,
        'to_recipients' => ['dest@example.test'],
        'cc_recipients' => [],
        'bcc_recipients' => [],
        'subject' => 'Rollback test',
        'queued_at' => now(),
    ]);

    $usage = app(OutboundUsageService::class);

    try {
        DB::transaction(function () use ($usage, $ctx, $message): void {
            $usage->reserve($ctx['user'], $message, 'rollback-key');

            throw new RuntimeException('simulated transaction failure');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(OutboundUsageReservation::query()->where('outbound_message_id', $message->id)->count())->toBe(0);

    $usageRow = SubscriptionUsage::query()->first();
    expect($usageRow === null || $usageRow->used_value === 0)->toBeTrue();
});

// -----------------------------------------------------------------------
// Cancellation release policy
// -----------------------------------------------------------------------

it('releases usage on cancellation before any transport attempt, freeing the allowance', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 1);

    $id = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx, ['idempotency_key' => 'cancel-me']))
        ->assertCreated()
        ->json('data.id');

    app(CancelOutboundMessageAction::class)->execute($id, $ctx['user']);

    $reservation = OutboundUsageReservation::query()->where('outbound_message_id', $id)->first();
    expect($reservation->state)->toBe(OutboundUsageReservationState::Released)
        ->and($reservation->release_reason)->toBe('cancelled_before_transport');

    expect(AuditLog::query()->where('action', 'outbound.usage_reservation_released')->exists())->toBeTrue();

    // The freed unit allows a new message to be sent.
    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx, ['idempotency_key' => 'after-cancel']))
        ->assertCreated();
});

it('does not independently release usage on delete of an already-committed message', function (): void {
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 1);
    Queue::fake();

    $id = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx, ['idempotency_key' => 'delete-me']))
        ->assertCreated()
        ->json('data.id');

    deliverOutboundUsageMessage($id, $ctx['transport']);
    expect(OutboundMessage::query()->find($id)->state)->toBe(OutboundMessageState::Sent);

    app(DeleteOutboundMessageAction::class)->execute($id, $ctx['user']);

    // Deleting (hiding) a message that has already been sent must never
    // touch usage accounting: the reservation stays committed.
    $reservation = OutboundUsageReservation::query()->where('outbound_message_id', $id)->first();
    expect($reservation->state)->toBe(OutboundUsageReservationState::Committed);
});

it('releases usage via the cancel step when deleting a still-queued message, not delete itself', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 1);

    $id = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx, ['idempotency_key' => 'delete-queued']))
        ->assertCreated()
        ->json('data.id');

    app(DeleteOutboundMessageAction::class)->execute($id, $ctx['user']);

    $reservation = OutboundUsageReservation::query()->where('outbound_message_id', $id)->first();
    expect($reservation->state)->toBe(OutboundUsageReservationState::Released)
        ->and($reservation->release_reason)->toBe('cancelled_before_transport');
});

// -----------------------------------------------------------------------
// Commit on accepted / sent, permanent failure policy, retries
// -----------------------------------------------------------------------

it('commits usage on delivery and does not double count a duplicate provider/job event', function (): void {
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 5);
    Queue::fake();

    $id = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertCreated()
        ->json('data.id');

    deliverOutboundUsageMessage($id, $ctx['transport']);

    $reservation = OutboundUsageReservation::query()->where('outbound_message_id', $id)->first();
    expect($reservation->state)->toBe(OutboundUsageReservationState::Committed);

    $usage = SubscriptionUsage::query()->first();
    expect($usage->used_value)->toBe(1);

    // Duplicate job execution / duplicate provider event must not double commit.
    app(OutboundUsageService::class)->commit($id);
    expect($usage->fresh()->used_value)->toBe(1);
});

it('does not release usage on permanent failure after a transport attempt', function (): void {
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 1);
    Queue::fake();

    $id = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertCreated()
        ->json('data.id');

    $ctx['transport']->setNextResult(OutboundDeliveryResult::permanentFailure('smtp_5xx', 'Nope'));
    deliverOutboundUsageMessage($id, $ctx['transport']);

    expect(OutboundMessage::query()->find($id)->state)->toBe(OutboundMessageState::Failed);

    $reservation = OutboundUsageReservation::query()->where('outbound_message_id', $id)->first();
    expect($reservation->state)->toBe(OutboundUsageReservationState::Reserved)
        ->and($reservation->metadata['permanent_failures'] ?? 0)->toBe(1);

    // Quota remains spent: a new message is rejected even though nothing was ever committed.
    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx, ['idempotency_key' => 'after-perm-fail']))
        ->assertStatus(429);
});

it('releases usage on failure before any transport attempt is made', function (): void {
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 1);
    Queue::fake();

    $id = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertCreated()
        ->json('data.id');

    // Deactivate the sending user so the job fails before the transport call.
    // `status` is intentionally excluded from User::$fillable, so this must
    // bypass mass assignment protection directly.
    $ctx['user']->forceFill(['status' => UserStatus::Suspended])->save();
    deliverOutboundUsageMessage($id, $ctx['transport']);

    expect(OutboundMessage::query()->find($id)->state)->toBe(OutboundMessageState::Failed);

    $reservation = OutboundUsageReservation::query()->where('outbound_message_id', $id)->first();
    expect($reservation->state)->toBe(OutboundUsageReservationState::Released)
        ->and($reservation->release_reason)->toBe('pre_transport_failure');
});

it('does not reserve a new unit on manual retry and counts the claimed attempt once', function (): void {
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 1);
    Queue::fake();

    $id = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertCreated()
        ->json('data.id');

    $ctx['transport']->setNextResult(OutboundDeliveryResult::temporaryFailure('smtp_4xx', 'Temp'));
    config(['outbound.send_max_attempts' => 3]);

    try {
        deliverOutboundUsageMessage($id, $ctx['transport']);
        expect(false)->toBeTrue('expected a temporary failure exception');
    } catch (RuntimeException) {
        // retryable failure re-throws to let the queue retry
    }

    expect(OutboundUsageReservation::query()->count())->toBe(1);
    $reservation = OutboundUsageReservation::query()->where('outbound_message_id', $id)->first();
    expect($reservation->metadata['attempts'] ?? 0)->toBe(1)
        ->and($reservation->metadata['retries'] ?? 0)->toBe(0);

    $ctx['transport']->setNextResult(OutboundDeliveryResult::accepted('fake', 'fake-msg-2'));
    deliverOutboundUsageMessage($id, $ctx['transport']);

    expect(OutboundUsageReservation::query()->count())->toBe(1);
    $reservation->refresh();
    expect($reservation->metadata['attempts'] ?? 0)->toBe(2)
        ->and($reservation->metadata['retries'] ?? 0)->toBe(1)
        ->and($reservation->state)->toBe(OutboundUsageReservationState::Committed);
});

// -----------------------------------------------------------------------
// Reset period / renewal / plan change
// -----------------------------------------------------------------------

it('starts a fresh usage period once the previous one has ended', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();
    $feature = attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 1);

    SubscriptionUsage::query()->create([
        'subscription_id' => $ctx['subscription']->id,
        'feature_id' => $feature->id,
        'used_value' => 1,
        'limit_value' => 1,
        'reset_period' => 'monthly',
        'period_start' => now()->subMonths(2)->startOfMonth(),
        'period_end' => now()->subMonths(2)->startOfMonth()->addMonthNoOverflow(),
    ]);

    // The expired period is exhausted, but a new period should open fresh.
    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertCreated();

    expect(SubscriptionUsage::query()->where('period_end', '>=', now())->count())->toBe(1);
});

it('rejects using the narrowed limit immediately after a plan change, without persisting it on the rolled-back reservation', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();
    $feature = attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 5);

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))->assertCreated();

    // Plan change narrows the limit below what is already reserved.
    $ctx['plan']->features()->updateExistingPivot($feature->id, [
        'feature_value' => ['limit' => 1, 'reset_period' => 'monthly'],
    ]);

    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'outbound_quota_messages_exceeded');

    expect(OutboundMessage::query()->count())->toBe(1);
});

it('persists a widened plan-change limit onto the usage row once a reservation succeeds under it', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();
    $feature = attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 1);

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))->assertCreated();
    expect(SubscriptionUsage::query()->first()->limit_value)->toBe(1);

    // A second message would exceed the original limit.
    $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertStatus(429);

    // Plan change widens the limit.
    $ctx['plan']->features()->updateExistingPivot($feature->id, [
        'feature_value' => ['limit' => 5, 'reset_period' => 'monthly'],
    ]);

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))->assertCreated();

    expect(SubscriptionUsage::query()->first()->limit_value)->toBe(5)
        ->and(OutboundMessage::query()->count())->toBe(2);
});

// -----------------------------------------------------------------------
// User-visible usage endpoint
// -----------------------------------------------------------------------

it('returns the user-visible usage summary with no abuse thresholds', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 10);

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))->assertCreated();

    $response = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-usage');

    $response->assertOk()
        ->assertJsonPath('data.messages_unlimited', false)
        ->assertJsonPath('data.recipients_unlimited', true)
        ->assertJsonPath('data.attachment_bytes_unlimited', true)
        ->assertJsonPath('data.entitlements.send_email', true)
        ->assertJsonPath('data.entitlements.reply_email', true)
        ->assertJsonPath('data.entitlements.forward_email', true);

    expect($response->json('data'))
        ->not->toHaveKey('abuse_limit')
        ->not->toHaveKey('rate_limit');
});

it('rejects unauthenticated and missing-scope usage summary requests', function (): void {
    $ctx = outboundUsageContext(['scopes' => ['outbound_messages:write']]);

    $this->getJson('/api/v1/outbound-usage')->assertUnauthorized();
    $this->withToken($ctx['token'])->getJson('/api/v1/outbound-usage')->assertForbidden();
});

// -----------------------------------------------------------------------
// Admin visibility, correction, and authorization gate
// -----------------------------------------------------------------------

it('denies non-admin actors from viewing or correcting another user\'s usage', function (): void {
    $ctx = outboundUsageContext();
    $stranger = User::factory()->create();

    $usage = app(OutboundUsageService::class);

    expect(fn () => $usage->adminSummaryForUser($stranger, $ctx['user']))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $usage->correctUsage($stranger, $ctx['user'], 'messages', 0, 'support_correction'))
        ->toThrow(AuthorizationException::class);
});

it('allows a platform admin to view and correct usage with an audited reason code', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();
    $feature = attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 5);
    $admin = outboundUsagePlatformAdmin();

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))->assertCreated();
    $id = OutboundMessage::query()->first()->id;
    deliverOutboundUsageMessage($id, $ctx['transport']);

    $usage = app(OutboundUsageService::class);
    $summary = $usage->adminSummaryForUser($admin, $ctx['user']);
    expect($summary['messages_used'])->toBe(1);

    $corrected = $usage->correctUsage($admin, $ctx['user'], 'messages', 0, 'support_correction');
    expect($corrected->used_value)->toBe(0);

    expect(AuditLog::query()->where('action', 'outbound.usage_corrected')->exists())->toBeTrue();
});

// -----------------------------------------------------------------------
// Reconciliation command / service
// -----------------------------------------------------------------------

it('dry-run reconciliation reports without mutating anything', function (): void {
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 5);
    Queue::fake();

    $id = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertCreated()
        ->json('data.id');

    deliverOutboundUsageMessage($id, $ctx['transport']);

    // Simulate a missed commit: message sent, but reservation still reserved.
    OutboundUsageReservation::query()->where('outbound_message_id', $id)->update([
        'state' => OutboundUsageReservationState::Reserved->value,
        'committed_at' => null,
    ]);
    SubscriptionUsage::query()->update(['used_value' => 0]);

    $report = app(OutboundUsageReconciliationService::class)->reconcile(dryRun: true, confirm: false, batchSize: 50);

    expect($report['missing_committed_usage'])->toBe(1)
        ->and($report['missing_committed_usage_repaired'])->toBe(0)
        ->and($report['mode'])->toBe('dry-run');

    expect(OutboundUsageReservation::query()->where('outbound_message_id', $id)->first()->state)
        ->toBe(OutboundUsageReservationState::Reserved);
    expect(SubscriptionUsage::query()->first()->used_value)->toBe(0);
});

it('confirmed reconciliation deterministically repairs missing committed usage', function (): void {
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 5);
    Queue::fake();

    $id = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertCreated()
        ->json('data.id');

    deliverOutboundUsageMessage($id, $ctx['transport']);

    OutboundUsageReservation::query()->where('outbound_message_id', $id)->update([
        'state' => OutboundUsageReservationState::Reserved->value,
        'committed_at' => null,
    ]);
    SubscriptionUsage::query()->update(['used_value' => 0]);

    $report = app(OutboundUsageReconciliationService::class)->reconcile(dryRun: false, confirm: true, batchSize: 50);

    expect($report['missing_committed_usage_repaired'])->toBe(1)
        ->and($report['mode'])->toBe('confirm');

    expect(OutboundUsageReservation::query()->where('outbound_message_id', $id)->first()->state)
        ->toBe(OutboundUsageReservationState::Committed);
    expect(SubscriptionUsage::query()->first()->used_value)->toBe(1);
});

it('does not auto-repair an ambiguous stale reservation on a still-queued message', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 5);

    $id = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertCreated()
        ->json('data.id');

    OutboundUsageReservation::query()->where('outbound_message_id', $id)->update([
        'expires_at' => now()->subHour(),
    ]);

    $report = app(OutboundUsageReconciliationService::class)->reconcile(dryRun: false, confirm: true, batchSize: 50);

    expect($report['ambiguous'])->toBe(1)
        ->and($report['stale_reserved_released'])->toBe(0);

    expect(OutboundUsageReservation::query()->where('outbound_message_id', $id)->first()->state)
        ->toBe(OutboundUsageReservationState::Reserved);
});

it('releases a stale reservation whose message was cancelled before transport', function (): void {
    Queue::fake();
    $ctx = outboundUsageContext();
    attachMeteredFeature($ctx['plan'], 'outbound_messages_per_period', 5);

    $id = $this->withToken($ctx['token'])
        ->postJson('/api/v1/outbound-messages', outboundUsagePayload($ctx))
        ->assertCreated()
        ->json('data.id');

    OutboundMessage::query()->whereKey($id)->update([
        'state' => OutboundMessageState::Cancelled->value,
        'cancelled_at' => now(),
    ]);
    OutboundUsageReservation::query()->where('outbound_message_id', $id)->update([
        'expires_at' => now()->subHour(),
    ]);

    $report = app(OutboundUsageReconciliationService::class)->reconcile(dryRun: false, confirm: true, batchSize: 50);

    expect($report['stale_reserved_released'])->toBe(1)
        ->and($report['ambiguous'])->toBe(0);

    expect(OutboundUsageReservation::query()->where('outbound_message_id', $id)->first()->state)
        ->toBe(OutboundUsageReservationState::Expired);
});

it('exposes the reconciliation artisan command with dry-run default', function (): void {
    $this->artisan('outbound:reconcile-usage')->assertSuccessful();
});

/**
 * Creates a received email owned by the context inbox with one clean,
 * safe attachment of the given size, ready to be forwarded.
 *
 * @return array{email: Email, attachment: Attachment}
 */
function createOutboundUsageForwardableEmail(array $ctx, int $attachmentBytes): array
{
    $email = Email::query()->create([
        'inbox_id' => $ctx['inbox']->id,
        'message_id' => 'usage-'.bin2hex(random_bytes(3)),
        'sender_email' => 'origin@example.test',
        'recipient_email' => $ctx['inbox']->full_address,
        'subject' => 'Original message',
        'received_at' => now()->subHour(),
        'size_bytes' => 100,
        'processing_status' => 'stored',
    ]);

    EmailBody::query()->create([
        'email_id' => $email->id,
        'text_body' => 'Original body',
        'html_body' => null,
        'storage_driver' => 'database',
    ]);

    $storagePath = 'quarantine/'.$email->id.'/usage-attachment.txt';
    Storage::disk('attachments')->put($storagePath, str_repeat('a', max(1, $attachmentBytes)));

    $attachment = Attachment::query()->create([
        'email_id' => $email->id,
        'original_filename' => 'file.txt',
        'stored_filename' => 'usage-attachment.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => $attachmentBytes,
        'checksum_sha256' => hash('sha256', 'usage-attachment'),
        'storage_disk' => 'attachments',
        'storage_path' => $storagePath,
        'scan_status' => AttachmentScanStatus::Clean,
        'is_safe' => true,
    ]);

    return ['email' => $email, 'attachment' => $attachment];
}
