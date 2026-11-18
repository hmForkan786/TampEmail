<?php

declare(strict_types=1);

use App\Actions\Outbound\CancelOutboundMessageAction;
use App\Actions\Outbound\DispatchDueOutboundMessagesAction;
use App\DTOs\Outbound\OutboundDeliveryResult;
use App\Enums\OutboundMessageState;
use App\Enums\ValueType;
use App\Jobs\DeliverOutboundMessageJob;
use App\Jobs\SendOutboundNotificationEmailJob;
use App\Models\Feature;
use App\Models\OutboundMessage;
use App\Models\OutboundNotification;
use App\Models\OutboundUsageReservation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\OutboundAttachmentSelector;
use App\Services\Outbound\OutboundAuthorizationService;
use App\Services\Outbound\OutboundDeliveryAttemptRecorder;
use App\Services\Outbound\OutboundDraftService;
use App\Services\Outbound\OutboundLaunchControlService;
use App\Services\Outbound\OutboundNotificationService;
use App\Services\Outbound\OutboundPruneService;
use App\Services\Outbound\OutboundSuppressionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::forget('outbound:rollout:override:emergency_stop');
    config([
        'api.key_hash_secret' => 'outbound-notification-test-secret',
        'outbound.enabled' => true,
        'outbound.send_enabled' => true,
        'outbound.schedule.enabled' => true,
        'outbound.transport' => 'unavailable',
        'outbound.rollout.mode' => 'enabled',
        'outbound.rollout.emergency_stop' => false,
        'outbound_usage.metering_enabled' => true,
        'outbound_notifications.usage_warning_percent' => 80,
        'outbound_notifications.mailer' => 'array',
        'queue.default' => 'sync',
    ]);
});

function deliverNotificationMessage(string $id, array $ctx): void
{
    (new DeliverOutboundMessageJob($id))->handle(
        $ctx['transport'],
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
        app(OutboundSuppressionService::class),
        app(OutboundDeliveryAttemptRecorder::class),
        app(OutboundLaunchControlService::class),
    );
}

function attachNotificationMeteredFeature(User $user, int $limit): void
{
    $subscription = Subscription::query()->where('user_id', $user->id)->firstOrFail();
    /** @var Plan $plan */
    $plan = $subscription->plan;
    $feature = Feature::query()->firstOrCreate(
        ['key' => 'outbound_messages_per_period'],
        [
            'name' => 'Outbound messages per period',
            'value_type' => ValueType::Json,
            'default_value' => ['limit' => null, 'reset_period' => 'monthly'],
            'is_active' => true,
            'display_order' => 20,
        ],
    );
    $plan->features()->syncWithoutDetaching([
        $feature->id => ['feature_value' => ['limit' => $limit, 'reset_period' => 'monthly']],
    ]);
}

function futureNotificationSchedule(int $minutesAhead = 120): array
{
    $utc = CarbonImmutable::now('UTC')->addMinutes($minutesAhead);

    return [
        'local_date' => $utc->format('Y-m-d'),
        'local_time' => $utc->format('H:i'),
        'timezone' => 'UTC',
    ];
}

function createNotificationDraft(array $ctx, array $overrides = []): OutboundMessage
{
    return app(OutboundDraftService::class)->create($ctx['user'], array_merge([
        'inbox_id' => $ctx['inbox']->id,
        'operation' => 'send',
        'to' => ['recipient@example.test'],
        'subject' => 'Notification subject secret',
        'text_body' => 'PRIVATE BODY',
        'bcc' => ['hidden@example.test'],
    ], $overrides));
}

function assertNotificationPayloadIsPrivate(array $payload): void
{
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('PRIVATE')
        ->and($encoded)->not->toContain('hidden@example.test')
        ->and($encoded)->not->toContain('Notification subject secret')
        ->and($payload)->not->toHaveKeys(['subject', 'text_body', 'html_body', 'to', 'cc', 'bcc', 'recipients']);
}

it('returns default notification preferences and updates them with version checks', function (): void {
    $ctx = outboundSendContext();

    $show = $this->withToken($ctx['token'])->getJson('/api/v1/outbound-notification-preferences');
    $show->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.notifications_enabled', true);

    $version = $show->json('data.version');
    $this->withToken($ctx['token'])->patchJson('/api/v1/outbound-notification-preferences', [
        'version' => $version,
        'email_enabled' => false,
        'events' => ['outbound.failed' => ['in_app' => true, 'email' => true]],
    ])->assertOk()->assertJsonPath('data.version', $version + 1)->assertJsonPath('data.email_enabled', false);

    $this->withToken($ctx['token'])->patchJson('/api/v1/outbound-notification-preferences', [
        'version' => $version,
        'email_enabled' => true,
    ])->assertStatus(409);

    $this->withToken($ctx['token'])->patchJson('/api/v1/outbound-notification-preferences', [
        'version' => $version + 1,
        'events' => ['outbound.unknown' => ['in_app' => true, 'email' => false]],
    ])->assertStatus(422);
});

it('lists filters reads dismisses and counts owner notifications via api', function (): void {
    $ctx = outboundSendContext();
    Queue::fake();

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundPayload($ctx))->assertCreated();
    $notification = OutboundNotification::query()->where('user_id', $ctx['user']->id)->firstOrFail();

    $this->withToken($ctx['token'])->getJson('/api/v1/outbound-notifications/unread-count')
        ->assertOk()->assertJsonPath('data.unread_count', 1);

    $this->withToken($ctx['token'])->getJson('/api/v1/outbound-notifications?unread=1')
        ->assertOk()->assertJsonCount(1, 'data.data');

    $this->withToken($ctx['token'])->getJson('/api/v1/outbound-notifications/'.$notification->id)
        ->assertOk()->assertJsonPath('data.id', $notification->id);

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-notifications/'.$notification->id.'/read')
        ->assertOk()->assertJsonPath('data.read_at', fn ($v) => $v !== null);

    $this->withToken($ctx['token'])->getJson('/api/v1/outbound-notifications/unread-count')
        ->assertJsonPath('data.unread_count', 0);

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-notifications/read-all')
        ->assertOk();

    $this->withToken($ctx['token'])->deleteJson('/api/v1/outbound-notifications/'.$notification->id)
        ->assertOk()->assertJsonPath('data.dismissed', true);

    $this->withToken($ctx['token'])->getJson('/api/v1/outbound-notifications')
        ->assertOk()->assertJsonCount(0, 'data.data');
});

it('returns not found for another owners notification endpoints', function (): void {
    $owner = outboundSendContext();
    $other = outboundSendContext();

    Queue::fake();
    $this->withToken($owner['token'])->postJson('/api/v1/outbound-messages', outboundPayload($owner))->assertCreated();
    $notification = OutboundNotification::query()->where('user_id', $owner['user']->id)->firstOrFail();

    $this->withToken($other['token'])->getJson('/api/v1/outbound-notifications/'.$notification->id)->assertNotFound();
    $this->withToken($other['token'])->postJson('/api/v1/outbound-notifications/'.$notification->id.'/read')->assertNotFound();
    $this->withToken($other['token'])->deleteJson('/api/v1/outbound-notifications/'.$notification->id)->assertNotFound();
});

it('creates lifecycle notifications for queued sent failed cancelled and scheduled events', function (): void {
    Queue::fake();
    $ctx = outboundSendContext();

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundPayload($ctx, [
        'idempotency_key' => 'queued-life',
        'bcc' => ['hidden@example.test'],
    ]))->assertCreated();
    expect(OutboundNotification::query()->where('event_type', 'outbound.queued')->count())->toBe(1);

    $messageId = OutboundMessage::query()->where('idempotency_key', 'queued-life')->value('id');
    deliverNotificationMessage((string) $messageId, $ctx);
    expect(OutboundNotification::query()->where('event_type', 'outbound.sent')->count())->toBe(1);

    $failedId = $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundPayload($ctx, [
        'idempotency_key' => 'failed-life',
    ]))->json('data.id');
    $ctx['transport']->setNextResult(OutboundDeliveryResult::permanentFailure('smtp_5xx', 'Nope'));
    deliverNotificationMessage((string) $failedId, $ctx);
    expect(OutboundNotification::query()->where('event_type', 'outbound.failed')->count())->toBe(1);

    $cancelId = $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundPayload($ctx, [
        'idempotency_key' => 'cancel-life',
    ]))->json('data.id');
    app(CancelOutboundMessageAction::class)->execute((string) $cancelId, $ctx['user']);
    expect(OutboundNotification::query()->where('event_type', 'outbound.cancelled')->count())->toBe(1);

    $draft = createNotificationDraft($ctx);
    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-drafts/'.$draft->id.'/schedule', array_merge([
        'version' => $draft->fresh()->draft_version,
    ], futureNotificationSchedule()))->assertOk();
    expect(OutboundNotification::query()->where('event_type', 'outbound.scheduled')->count())->toBe(1);

    $payload = OutboundNotification::query()->latest()->firstOrFail()->payload;
    assertNotificationPayloadIsPrivate($payload);
});

it('creates schedule deferred and schedule failed notifications with throttled keys', function (): void {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-07-26 08:00:00');
    $ctx = outboundSendContext();
    $draft = createNotificationDraft($ctx);
    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-drafts/'.$draft->id.'/schedule', array_merge([
        'version' => $draft->fresh()->draft_version,
    ], futureNotificationSchedule(60)))->assertOk();

    app(OutboundLaunchControlService::class)->setEmergencyStop(true, $ctx['user']);
    CarbonImmutable::setTestNow('2026-07-26 09:30:00');
    app(DispatchDueOutboundMessagesAction::class)->execute(50);
    app(DispatchDueOutboundMessagesAction::class)->execute(50);

    expect(OutboundNotification::query()->where('event_type', 'outbound.schedule_deferred')->count())->toBe(1);

    app(OutboundLaunchControlService::class)->setEmergencyStop(false, $ctx['user']);
    Cache::forget('outbound:rollout:override:emergency_stop');

    $failCtx = outboundSendContext();
    CarbonImmutable::setTestNow('2026-07-26 08:00:00');
    $failedDraft = createNotificationDraft($failCtx);
    $this->withToken($failCtx['token'])->postJson('/api/v1/outbound-drafts/'.$failedDraft->id.'/schedule', array_merge([
        'version' => $failedDraft->fresh()->draft_version,
    ], futureNotificationSchedule(60)))->assertOk();
    app(OutboundSuppressionService::class)->suppress('recipient@example.test', 'manual', 'test');
    CarbonImmutable::setTestNow('2026-07-26 09:30:00');
    app(DispatchDueOutboundMessagesAction::class)->execute(50);

    expect(OutboundNotification::query()->where('event_type', 'outbound.schedule_failed')->count())->toBe(1);

    CarbonImmutable::setTestNow();
});

it('creates usage warning and exhausted notifications once per billing period', function (): void {
    Queue::fake();
    $ctx = outboundSendContext();
    attachNotificationMeteredFeature($ctx['user'], 5);

    for ($i = 0; $i < 4; $i++) {
        $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundPayload($ctx, [
            'idempotency_key' => 'usage-'.$i,
        ]))->assertCreated();
    }

    expect(OutboundNotification::query()->where('event_type', 'outbound.usage_warning')->count())->toBe(1)
        ->and(OutboundNotification::query()->where('event_type', 'outbound.usage_exhausted')->count())->toBe(0);

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundPayload($ctx, [
        'idempotency_key' => 'usage-exhaust',
    ]))->assertCreated();

    expect(OutboundNotification::query()->where('event_type', 'outbound.usage_exhausted')->count())->toBe(1);

    $warning = OutboundNotification::query()->where('event_type', 'outbound.usage_warning')->firstOrFail();
    expect($warning->payload['percentage'] ?? null)->toBeInt()
        ->and($warning->outbound_message_id)->toBeNull();
    assertNotificationPayloadIsPrivate($warning->payload);

    expect(OutboundUsageReservation::query()->count())->toBe(5);
});

it('deduplicates notifications by idempotency key', function (): void {
    $ctx = outboundSendContext();
    $service = app(OutboundNotificationService::class);
    $message = OutboundMessage::query()->create([
        'user_id' => $ctx['user']->id,
        'inbox_id' => $ctx['inbox']->id,
        'operation' => 'send',
        'state' => OutboundMessageState::Queued,
        'idempotency_key' => 'dedupe-key',
        'request_fingerprint' => hash('sha256', 'x'),
        'from_address' => $ctx['inbox']->full_address,
        'to_recipients' => ['recipient@example.test'],
        'subject' => 'Secret subject',
        'text_body' => 'Secret body',
        'attempt_count' => 0,
        'queued_at' => now(),
    ]);

    $first = $service->notify($ctx['user'], 'outbound.queued', $message, [], 'queued:'.$message->id);
    $second = $service->notify($ctx['user'], 'outbound.queued', $message, [], 'queued:'.$message->id);

    expect($first?->id)->toBe($second?->id)
        ->and(OutboundNotification::query()->where('event_type', 'outbound.queued')->count())->toBe(1);
});

it('queues notification email on the notifications mailer without outbound side effects', function (): void {
    Queue::fake();
    $ctx = outboundSendContext();

    app(OutboundNotificationService::class)->updatePreference($ctx['user'], [
        'events' => ['outbound.failed' => ['in_app' => true, 'email' => true]],
    ], app(OutboundNotificationService::class)->preference($ctx['user'])->version);

    $message = OutboundMessage::query()->create([
        'user_id' => $ctx['user']->id,
        'inbox_id' => $ctx['inbox']->id,
        'operation' => 'send',
        'state' => OutboundMessageState::Failed,
        'idempotency_key' => 'email-notify',
        'request_fingerprint' => hash('sha256', 'y'),
        'from_address' => $ctx['inbox']->full_address,
        'to_recipients' => ['recipient@example.test'],
        'subject' => 'Secret',
        'text_body' => 'Secret',
        'attempt_count' => 1,
        'failed_at' => now(),
    ]);

    $beforeMessages = OutboundMessage::query()->count();
    $beforeReservations = OutboundUsageReservation::query()->count();

    app(OutboundNotificationService::class)->notify($ctx['user'], 'outbound.failed', $message, ['failure_category' => 'transport_error'], 'failed:'.$message->id);

    Queue::assertPushed(SendOutboundNotificationEmailJob::class, 1);
    expect(OutboundMessage::query()->count())->toBe($beforeMessages)
        ->and(OutboundUsageReservation::query()->count())->toBe($beforeReservations);

    $notification = OutboundNotification::query()->firstOrFail();
    (new SendOutboundNotificationEmailJob($notification->id))->handle();
    expect($notification->fresh()->email_sent_at)->not->toBeNull();
});

it('renders the notifications index page for the signed in owner', function (): void {
    $ctx = outboundSendContext();
    Queue::fake();
    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundPayload($ctx))->assertCreated();

    $this->actingAs($ctx['user'])->get(route('outbound-notifications.index'))
        ->assertOk()
        ->assertSee('Notifications')
        ->assertSee('queued')
        ->assertDontSee('PRIVATE BODY');
});

it('lets the signed in owner dismiss a notification from the web UI', function (): void {
    $ctx = outboundSendContext();
    Queue::fake();
    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundPayload($ctx))->assertCreated();
    $notification = OutboundNotification::query()->where('user_id', $ctx['user']->id)->firstOrFail();

    $this->actingAs($ctx['user'])
        ->from(route('outbound-notifications.index'))
        ->delete(route('outbound-notifications.destroy', $notification), [
            '_token' => csrf_token(),
        ])
        ->assertRedirect();

    expect($notification->fresh()->dismissed_at)->not->toBeNull();
    $this->actingAs($ctx['user'])->get(route('outbound-notifications.index'))
        ->assertDontSee($notification->payload['summary']);
});

it('does not let another signed in user dismiss an owners notification', function (): void {
    $owner = outboundSendContext();
    $other = outboundSendContext();
    Queue::fake();
    $this->withToken($owner['token'])->postJson('/api/v1/outbound-messages', outboundPayload($owner))->assertCreated();
    $notification = OutboundNotification::query()->where('user_id', $owner['user']->id)->firstOrFail();

    $this->actingAs($other['user'])
        ->from(route('outbound-notifications.index'))
        ->delete(route('outbound-notifications.destroy', $notification), [
            '_token' => csrf_token(),
        ])
        ->assertNotFound();

    expect($notification->fresh()->dismissed_at)->toBeNull();
});

it('prunes expired notifications through the bounded outbound retention path', function (): void {
    $ctx = outboundSendContext();
    Queue::fake();
    config([
        'outbound_retention.cleanup_enabled' => true,
        'outbound_notifications.retention_days' => 90,
    ]);
    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundPayload($ctx))->assertCreated();
    $expired = OutboundNotification::query()->where('user_id', $ctx['user']->id)->firstOrFail();
    $expired->forceFill(['created_at' => now()->subDays(91)])->save();

    $fresh = $expired->replicate(['idempotency_key']);
    $fresh->forceFill([
        'idempotency_key' => 'fresh-'.$expired->id,
        'created_at' => now()->subDays(10),
        'payload' => ['summary' => 'Fresh outbound status'],
    ])->save();

    $dryRun = app(OutboundPruneService::class)->prune(true, false, 50);
    expect($dryRun['eligible_notifications'])->toBe(1)
        ->and($expired->fresh())->not->toBeNull()
        ->and($fresh->fresh())->not->toBeNull();

    $report = app(OutboundPruneService::class)->prune(false, true, 1);
    expect($report['notifications_deleted'])->toBe(1)
        ->and($expired->fresh())->toBeNull()
        ->and($fresh->fresh())->not->toBeNull();

    $empty = app(OutboundPruneService::class)->prune(false, true, 50);
    expect($empty['eligible_notifications'])->toBe(0)
        ->and($empty['notifications_deleted'])->toBe(0)
        ->and($fresh->fresh())->not->toBeNull();
});

it('exposes scheduled messages preferences and unread badge in primary navigation', function (): void {
    $ctx = outboundSendContext();
    Queue::fake();
    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundPayload($ctx))->assertCreated();

    $this->actingAs($ctx['user'])->get(route('outbound-notifications.index'))
        ->assertOk()
        ->assertSee('Scheduled')
        ->assertSee('Notification Preferences')
        ->assertSee('(1)', false)
        ->assertSee('aria-label="1 unread notifications"', false);
});
