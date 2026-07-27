<?php

declare(strict_types=1);

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Actions\Outbound\DispatchDueOutboundMessagesAction;
use App\Enums\OutboundMessageState;
use App\Jobs\DeliverOutboundMessageJob;
use App\Jobs\SendOutboundNotificationEmailJob;
use App\Models\AuditLog;
use App\Models\OutboundMessage;
use App\Models\OutboundUsageReservation;
use App\Models\User;
use App\Services\Outbound\OutboundDraftService;
use App\Services\Outbound\OutboundLaunchControlService;
use App\Services\Outbound\OutboundPruneService;
use App\Services\Outbound\OutboundSuppressionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::forget('outbound:rollout:override:emergency_stop');
    config([
        'api.key_hash_secret' => 'outbound-schedule-test-secret',
        'outbound.enabled' => true,
        'outbound.send_enabled' => true,
        'outbound.schedule.enabled' => true,
        'outbound.rollout.mode' => 'enabled',
        'outbound.rollout.emergency_stop' => false,
        'queue.default' => 'sync',
    ]);
});

/**
 * @return array{local_date: string, local_time: string, timezone: string}
 */
function futureScheduleFields(string $timezone = 'UTC', int $minutesAhead = 120): array
{
    $utc = CarbonImmutable::now('UTC')->addMinutes($minutesAhead);

    return [
        'local_date' => $utc->setTimezone($timezone)->format('Y-m-d'),
        'local_time' => $utc->setTimezone($timezone)->format('H:i'),
        'timezone' => $timezone,
    ];
}

function createSendableDraft(array $ctx, array $overrides = []): OutboundMessage
{
    return app(OutboundDraftService::class)->create($ctx['user'], array_merge([
        'inbox_id' => $ctx['inbox']->id,
        'operation' => 'send',
        'to' => ['recipient@example.test'],
        'subject' => 'Scheduled subject',
        'text_body' => 'Scheduled body',
    ], $overrides));
}

function scheduleDraftViaApi(array $ctx, OutboundMessage $draft, array $scheduleOverrides = []): TestResponse
{
    return test()->withToken($ctx['token'])->postJson('/api/v1/outbound-drafts/'.$draft->id.'/schedule', array_merge([
        'version' => $draft->fresh()->draft_version,
    ], futureScheduleFields(), $scheduleOverrides));
}

it('schedules a valid draft without usage reservation or delivery job', function (): void {
    Queue::fake();
    $ctx = outboundSendContext();
    $draft = createSendableDraft($ctx);

    scheduleDraftViaApi($ctx, $draft)
        ->assertOk()
        ->assertJsonPath('data.state', 'scheduled')
        ->assertJsonPath('data.schedule_version', 1)
        ->assertJsonPath('data.can_unschedule', true)
        ->assertJsonPath('data.can_send_now', true)
        ->assertJsonMissingPath('data.scheduled_claimed_at')
        ->assertJsonMissingPath('data.schedule_defer_reason');

    expect(OutboundUsageReservation::query()->count())->toBe(1);
    Queue::assertNothingPushed();
    expect(AuditLog::query()->where('action', 'outbound.schedule_created')->count())->toBe(1);
});

it('rejects scheduling an incomplete draft', function (): void {
    $ctx = outboundSendContext();
    $draft = app(OutboundDraftService::class)->create($ctx['user'], [
        'inbox_id' => $ctx['inbox']->id,
        'operation' => 'send',
        'subject' => 'No recipients',
    ]);

    scheduleDraftViaApi($ctx, $draft)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'recipients_required');
});

it('rejects past and boundary schedule times', function (): void {
    CarbonImmutable::setTestNow('2026-07-26 12:00:00');
    $ctx = outboundSendContext();
    $draft = createSendableDraft($ctx);

    test()->withToken($ctx['token'])->postJson('/api/v1/outbound-drafts/'.$draft->id.'/schedule', [
        'version' => $draft->draft_version,
        'local_date' => '2026-07-25',
        'local_time' => '10:00',
        'timezone' => 'UTC',
    ])->assertStatus(422)->assertJsonPath('error.code', 'schedule_time_invalid');

    test()->withToken($ctx['token'])->postJson('/api/v1/outbound-drafts/'.$draft->id.'/schedule', [
        'version' => $draft->draft_version,
        'local_date' => '2026-07-26',
        'local_time' => '12:00',
        'timezone' => 'UTC',
    ])->assertStatus(422)->assertJsonPath('error.code', 'schedule_time_invalid');

    CarbonImmutable::setTestNow();
});

it('rejects invalid timezones and accepts valid ones', function (): void {
    $ctx = outboundSendContext();
    $draft = createSendableDraft($ctx);

    scheduleDraftViaApi($ctx, $draft, ['timezone' => 'Not/AZone'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'schedule_timezone_invalid');

    scheduleDraftViaApi($ctx, $draft, futureScheduleFields('America/New_York'))
        ->assertOk()
        ->assertJsonPath('data.scheduled_timezone', 'America/New_York');
});

it('rejects DST gap times and resolves overlap to the earlier occurrence', function (): void {
    CarbonImmutable::setTestNow('2026-03-08 06:00:00');
    $ctx = outboundSendContext();
    $draft = createSendableDraft($ctx);

    test()->withToken($ctx['token'])->postJson('/api/v1/outbound-drafts/'.$draft->id.'/schedule', [
        'version' => $draft->draft_version,
        'local_date' => '2026-03-08',
        'local_time' => '02:30',
        'timezone' => 'America/New_York',
    ])->assertStatus(422)->assertJsonPath('error.code', 'schedule_time_invalid');

    CarbonImmutable::setTestNow('2030-11-02 12:00:00');
    $draft2 = createSendableDraft($ctx);

    $response = test()->withToken($ctx['token'])->postJson('/api/v1/outbound-drafts/'.$draft2->id.'/schedule', [
        'version' => $draft2->draft_version,
        'local_date' => '2030-11-03',
        'local_time' => '01:30',
        'timezone' => 'America/New_York',
    ])->assertOk();

    $scheduledAt = CarbonImmutable::parse($response->json('data.scheduled_at'));
    expect($scheduledAt->utc()->format('Y-m-d H:i:s'))->toBe('2030-11-03 05:30:00');

    CarbonImmutable::setTestNow();
});

it('returns ownership-safe 404 and rejects stale draft version conflicts', function (): void {
    $ctx = outboundSendContext();
    $draft = createSendableDraft($ctx);
    $other = User::factory()->create();
    $otherToken = app(CreateApiKeyAction::class)->issue(
        userId: $other->id,
        name: 'other',
        permissions: ['outbound_messages:write'],
        user: $other,
    )->plainToken;

    test()->withToken($otherToken)->postJson('/api/v1/outbound-drafts/'.$draft->id.'/schedule', array_merge([
        'version' => $draft->draft_version,
    ], futureScheduleFields()))->assertNotFound();

    test()->withToken($ctx['token'])->postJson('/api/v1/outbound-drafts/'.$draft->id.'/schedule', array_merge([
        'version' => 999,
    ], futureScheduleFields()))->assertStatus(409)->assertJsonPath('error.code', 'draft_conflict');
});

it('reschedules owned scheduled messages with version increments', function (): void {
    $ctx = outboundSendContext();
    $draft = createSendableDraft($ctx);
    scheduleDraftViaApi($ctx, $draft)->assertOk();
    $message = OutboundMessage::query()->findOrFail($draft->id);

    test()->withToken($ctx['token'])->patchJson('/api/v1/outbound-messages/'.$message->id.'/schedule', array_merge([
        'schedule_version' => 1,
    ], futureScheduleFields('Europe/London', 180)))
        ->assertOk()
        ->assertJsonPath('data.schedule_version', 2)
        ->assertJsonPath('data.scheduled_timezone', 'Europe/London');

    expect(AuditLog::query()->where('action', 'outbound.schedule_updated')->count())->toBe(1);
});

it('rejects stale schedule conflicts and claimed reschedules', function (): void {
    $ctx = outboundSendContext();
    $draft = createSendableDraft($ctx);
    scheduleDraftViaApi($ctx, $draft);
    $message = OutboundMessage::query()->findOrFail($draft->id);

    test()->withToken($ctx['token'])->patchJson('/api/v1/outbound-messages/'.$message->id.'/schedule', array_merge([
        'schedule_version' => 99,
    ], futureScheduleFields()))->assertStatus(409)->assertJsonPath('error.code', 'schedule_conflict');

    $message->forceFill(['scheduled_claimed_at' => now()])->save();

    test()->withToken($ctx['token'])->patchJson('/api/v1/outbound-messages/'.$message->id.'/schedule', array_merge([
        'schedule_version' => 1,
    ], futureScheduleFields()))->assertStatus(409)->assertJsonPath('error.code', 'schedule_already_dispatched');
});

it('unschedules to draft retaining content without usage', function (): void {
    $ctx = outboundSendContext();
    $draft = createSendableDraft($ctx);
    scheduleDraftViaApi($ctx, $draft);
    $message = OutboundMessage::query()->findOrFail($draft->id);

    test()->withToken($ctx['token'])->deleteJson('/api/v1/outbound-messages/'.$message->id.'/schedule', [
        'schedule_version' => 1,
    ])->assertOk()
        ->assertJsonPath('data.state', 'draft')
        ->assertJsonMissingPath('data.scheduled_at')
        ->assertJsonMissingPath('data.schedule_version');

    $fresh = $message->fresh();
    expect($fresh->state)->toBe(OutboundMessageState::Draft)
        ->and($fresh->subject)->toBe('Scheduled subject')
        ->and($fresh->text_body)->toBe('Scheduled body')
        ->and($fresh->scheduled_at)->toBeNull();
    expect(OutboundUsageReservation::query()->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'outbound.schedule_cancelled')->count())->toBe(1);
});

it('send now queues once with usage and rejects non-owners', function (): void {
    Queue::fake();
    $ctx = outboundSendContext();
    $draft = createSendableDraft($ctx);
    scheduleDraftViaApi($ctx, $draft);
    $message = OutboundMessage::query()->findOrFail($draft->id);

    test()->withToken($ctx['token'])->postJson('/api/v1/outbound-messages/'.$message->id.'/send-now', [
        'schedule_version' => 1,
    ])->assertOk()->assertJsonPath('data.state', 'queued');

    test()->withToken($ctx['token'])->postJson('/api/v1/outbound-messages/'.$message->id.'/send-now', [
        'schedule_version' => 1,
    ])->assertOk()->assertJsonPath('data.state', 'queued');

    expect(OutboundUsageReservation::query()->count())->toBe(1);
    Queue::assertPushed(DeliverOutboundMessageJob::class, 1);
    expect(AuditLog::query()->where('action', 'outbound.schedule_dispatched')->count())->toBe(1);
});

it('send now fails closed on security violations without queueing', function (): void {
    Queue::fake();
    $ctx = outboundSendContext();
    $draft = createSendableDraft($ctx);
    scheduleDraftViaApi($ctx, $draft);
    $message = OutboundMessage::query()->findOrFail($draft->id);

    app(OutboundSuppressionService::class)->suppress(
        'recipient@example.test',
        'manual',
        'test',
    );

    test()->withToken($ctx['token'])->postJson('/api/v1/outbound-messages/'.$message->id.'/send-now', [
        'schedule_version' => 1,
    ])->assertStatus(422);

    expect($message->fresh()->state)->toBe(OutboundMessageState::Scheduled);
    Queue::assertNothingPushed();
    expect(OutboundUsageReservation::query()->count())->toBe(1);
});

it('dispatcher ignores future messages and queues due ones once', function (): void {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-07-26 08:00:00');
    $ctx = outboundSendContext();
    $futureDraft = createSendableDraft($ctx);
    scheduleDraftViaApi($ctx, $futureDraft, futureScheduleFields('UTC', 240));

    $dueDraft = createSendableDraft($ctx);
    scheduleDraftViaApi($ctx, $dueDraft, futureScheduleFields('UTC', 60));

    CarbonImmutable::setTestNow('2026-07-26 09:30:00');

    $stats = app(DispatchDueOutboundMessagesAction::class)->execute(50);
    expect($stats)->toMatchArray(['processed' => 1, 'dispatched' => 1, 'deferred' => 0, 'failed' => 0]);

    $stats2 = app(DispatchDueOutboundMessagesAction::class)->execute(50);
    expect($stats2['dispatched'])->toBe(0);

    expect(OutboundMessage::query()->findOrFail($futureDraft->id)->state)->toBe(OutboundMessageState::Scheduled);
    expect(OutboundMessage::query()->findOrFail($dueDraft->id)->state)->toBe(OutboundMessageState::Queued);
    Queue::assertPushed(DeliverOutboundMessageJob::class, 1);

    CarbonImmutable::setTestNow();
});

it('defers dispatch during emergency stop without usage or jobs', function (): void {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-07-26 08:00:00');
    $ctx = outboundSendContext();
    $draft = createSendableDraft($ctx);
    scheduleDraftViaApi($ctx, $draft, futureScheduleFields('UTC', 60));

    app(OutboundLaunchControlService::class)->setEmergencyStop(true, $ctx['user']);

    CarbonImmutable::setTestNow('2026-07-26 09:30:00');

    $stats = app(DispatchDueOutboundMessagesAction::class)->execute(50);
    expect($stats['processed'])->toBe(1)
        ->and($stats['deferred'])->toBe(1);
    expect(OutboundMessage::query()->findOrFail($draft->id)->state)->toBe(OutboundMessageState::Scheduled);
    expect(OutboundUsageReservation::query()->count())->toBe(1);
    Queue::assertNothingPushed();
    expect(AuditLog::query()->where('action', 'outbound.schedule_dispatch_deferred')->count())->toBe(1);

    CarbonImmutable::setTestNow();
});

it('returns suppressed scheduled messages to draft on permanent dispatch failure', function (): void {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-07-26 08:00:00');
    $ctx = outboundSendContext();
    $draft = createSendableDraft($ctx);
    scheduleDraftViaApi($ctx, $draft, futureScheduleFields('UTC', 60));

    app(OutboundSuppressionService::class)->suppress(
        'recipient@example.test',
        'manual',
        'test',
    );

    CarbonImmutable::setTestNow('2026-07-26 09:30:00');

    $stats = app(DispatchDueOutboundMessagesAction::class)->execute(50);
    expect($stats['failed'])->toBe(1);
    expect(OutboundMessage::query()->findOrFail($draft->id)->state)->toBe(OutboundMessageState::Draft);
    expect(OutboundUsageReservation::query()->count())->toBe(1);
    Queue::assertNotPushed(DeliverOutboundMessageJob::class);
    Queue::assertPushed(SendOutboundNotificationEmailJob::class, 1);
    expect(AuditLog::query()->where('action', 'outbound.schedule_dispatch_failed')->count())->toBe(1);

    CarbonImmutable::setTestNow();
});

it('does not prune scheduled messages as drafts', function (): void {
    Queue::fake();
    config(['outbound_retention.cleanup_enabled' => true, 'outbound_retention.draft_days' => 30]);
    $ctx = outboundSendContext();
    $draft = createSendableDraft($ctx);
    scheduleDraftViaApi($ctx, $draft);
    OutboundMessage::query()->whereKey($draft->id)->update(['updated_at' => now()->subDays(31)]);

    app(OutboundPruneService::class)->prune(false, true, 100);

    $fresh = OutboundMessage::query()->findOrFail($draft->id);
    expect($fresh->state)->toBe(OutboundMessageState::Scheduled)
        ->and($fresh->draft_deleted_at)->toBeNull()
        ->and($fresh->text_body)->toBe('Scheduled body');
});

it('requires write scope for schedule endpoints', function (): void {
    $readOnly = outboundSendContext(['scopes' => ['outbound_messages:read']]);
    $draft = createSendableDraft($readOnly);

    test()->withToken($readOnly['token'])->postJson('/api/v1/outbound-drafts/'.$draft->id.'/schedule', array_merge([
        'version' => $draft->draft_version,
    ], futureScheduleFields()))->assertForbidden();
});

it('renders schedule UI on draft and scheduled message pages', function (): void {
    $ctx = outboundSendContext();
    $draft = createSendableDraft($ctx);

    test()->actingAs($ctx['user'])->get(route('outbound-drafts.edit', $draft))
        ->assertOk()
        ->assertSee('Schedule for later')
        ->assertSee('America/New_York');

    scheduleDraftViaApi($ctx, $draft);
    $message = OutboundMessage::query()->findOrFail($draft->id);

    test()->actingAs($ctx['user'])->get(route('outbound-messages.show', $message))
        ->assertOk()
        ->assertSee('Scheduled for')
        ->assertSee('Send now')
        ->assertSee('Cancel schedule')
        ->assertSee('Scheduled for delivery');
});

it('maps schedule audit events on the timeline', function (): void {
    $ctx = outboundSendContext();
    $draft = createSendableDraft($ctx);
    scheduleDraftViaApi($ctx, $draft);
    $message = OutboundMessage::query()->findOrFail($draft->id);

    $timeline = test()->withToken($ctx['token'])->getJson('/api/v1/outbound-messages/'.$message->id.'/timeline')
        ->assertOk()
        ->json('data.timeline');

    expect(collect($timeline)->pluck('label')->all())->toContain('Scheduled for delivery');
});
