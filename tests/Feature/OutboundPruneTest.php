<?php

declare(strict_types=1);

use App\Enums\AttachmentScanStatus;
use App\Enums\BillingCycle;
use App\Enums\OutboundDeliveryAttemptState;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Enums\OutboundProviderEventType;
use App\Enums\SubscriptionStatus;
use App\Models\Attachment;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Inbox;
use App\Models\OutboundDeliveryAttempt;
use App\Models\OutboundMessage;
use App\Models\OutboundProviderEvent;
use App\Models\OutboundRecipientSuppression;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Outbound\OutboundPruneService;
use App\Services\Outbound\OutboundSuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'outbound_retention.cleanup_enabled' => true,
        'outbound_retention.free_days' => 1,
        'outbound_retention.premium_days' => 30,
        'outbound_retention.attempt_days' => 90,
        'outbound_retention.provider_event_days' => 90,
        'outbound_retention.batch_size' => 500,
    ]);
});

/**
 * @return array{user: User, domain: Domain, inbox: Inbox, plan: Plan}
 */
function outboundPruneContext(bool $free = true): array
{
    $user = User::factory()->create();

    $plan = Plan::query()->create([
        'slug' => 'prune-'.uniqid(),
        'name' => 'Prune Plan',
        'price_monthly' => '0.00',
        'price_yearly' => '0.00',
        'currency' => 'USD',
        'is_free' => $free,
        'is_active' => true,
        'display_order' => 1,
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
        'domain' => 'prune-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Prune',
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

    return compact('user', 'domain', 'inbox', 'plan');
}

/**
 * @param  array{user: User, inbox: Inbox}  $ctx
 */
function makePruneMessage(array $ctx, array $overrides = []): OutboundMessage
{
    $createdAt = $overrides['created_at'] ?? now();
    unset($overrides['created_at']);

    $message = OutboundMessage::query()->create(array_merge([
        'user_id' => $ctx['user']->id,
        'inbox_id' => $ctx['inbox']->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Sent,
        'idempotency_key' => 'prune-'.uniqid(),
        'request_fingerprint' => hash('sha256', 'fp-'.uniqid()),
        'from_address' => $ctx['inbox']->full_address,
        'to_recipients' => ['secret-recipient@example.test'],
        'subject' => 'Secret subject content',
        'text_body' => 'Secret body content',
        'sent_at' => now(),
    ], $overrides));

    $message->forceFill(['created_at' => $createdAt])->save();

    return $message->fresh();
}

// --- Fail-closed / blocked ------------------------------------------------

it('blocks and mutates nothing when outbound retention cleanup is disabled', function (): void {
    config(['outbound_retention.cleanup_enabled' => false]);
    $ctx = outboundPruneContext();
    $message = makePruneMessage($ctx, ['created_at' => now()->subDays(10)]);

    $report = app(OutboundPruneService::class)->prune(false, true, 500);

    expect($report['blocked'])->toBeTrue()
        ->and($report['blocked_reason'])->toBe('disabled')
        ->and($message->fresh()->content_redacted_at)->toBeNull();
});

// --- Dry run ----------------------------------------------------------------

it('changes nothing on a dry run even when items are eligible', function (): void {
    $ctx = outboundPruneContext();
    $message = makePruneMessage($ctx, ['created_at' => now()->subDays(10)]);

    $report = app(OutboundPruneService::class)->prune(true, false, 500);

    expect($report['eligible_content_redaction'])->toBeGreaterThanOrEqual(1)
        ->and($report['content_redacted'])->toBe(0)
        ->and($message->fresh()->content_redacted_at)->toBeNull()
        ->and($message->fresh()->subject)->toBe('Secret subject content');
});

it('is a dry run whenever --confirm is absent regardless of the --dry-run flag', function (): void {
    $ctx = outboundPruneContext();
    $message = makePruneMessage($ctx, ['created_at' => now()->subDays(10)]);

    $report = app(OutboundPruneService::class)->prune(false, false, 500);

    expect($report['content_redacted'])->toBe(0)
        ->and($message->fresh()->content_redacted_at)->toBeNull();
});

// --- Content redaction ----------------------------------------------------

it('redacts content for a free-plan message past its retention window', function (): void {
    $ctx = outboundPruneContext(free: true);
    $message = makePruneMessage($ctx, ['created_at' => now()->subDays(2)]);

    $report = app(OutboundPruneService::class)->prune(false, true, 500);
    $fresh = $message->fresh();

    expect($report['content_redacted'])->toBe(1)
        ->and($fresh->content_redacted_at)->not->toBeNull()
        ->and($fresh->subject)->toBe('[redacted]')
        ->and($fresh->text_body)->toBeNull()
        ->and($fresh->html_body)->toBeNull()
        ->and($fresh->from_display_name)->toBeNull()
        ->and($fresh->attachment_ids)->toBeNull();
});

it('minimizes recipient data to hashes instead of leaving plaintext addresses', function (): void {
    $ctx = outboundPruneContext(free: true);
    $message = makePruneMessage($ctx, [
        'created_at' => now()->subDays(2),
        'to_recipients' => ['alice@example.test'],
        'cc_recipients' => ['bob@example.test'],
    ]);

    app(OutboundPruneService::class)->prune(false, true, 500);
    $fresh = $message->fresh();

    expect($fresh->to_recipients)->toHaveCount(1)
        ->and($fresh->to_recipients[0])->toStartWith('sha256:')
        ->and($fresh->to_recipients[0])->not->toContain('alice')
        ->and($fresh->cc_recipients[0])->toStartWith('sha256:');
});

it('does not redact a message still within its retention window', function (): void {
    $ctx = outboundPruneContext(free: true);
    $message = makePruneMessage($ctx, ['created_at' => now()]);

    app(OutboundPruneService::class)->prune(false, true, 500);

    expect($message->fresh()->content_redacted_at)->toBeNull()
        ->and($message->fresh()->subject)->toBe('Secret subject content');
});

it('never redacts a message when the resolved retention is disabled (0)', function (): void {
    config(['outbound_retention.free_days' => 0]);
    $ctx = outboundPruneContext(free: true);
    $message = makePruneMessage($ctx, ['created_at' => now()->subDays(100)]);

    app(OutboundPruneService::class)->prune(false, true, 500);

    expect($message->fresh()->content_redacted_at)->toBeNull();
});

it('preserves safe operational state through content redaction', function (): void {
    $ctx = outboundPruneContext(free: true);
    $message = makePruneMessage($ctx, [
        'created_at' => now()->subDays(2),
        'state' => OutboundMessageState::Failed,
        'failure_code' => 'smtp_5xx',
        'failed_at' => now(),
        'provider_message_id' => 'provider-msg-123',
    ]);

    app(OutboundPruneService::class)->prune(false, true, 500);
    $fresh = $message->fresh();

    expect($fresh->state)->toBe(OutboundMessageState::Failed)
        ->and($fresh->failure_code)->toBe('smtp_5xx')
        ->and($fresh->provider_message_id)->toBe('provider-msg-123')
        ->and($fresh->id)->toBe($message->id);
});

it('never deletes the shared source attachment during content redaction', function (): void {
    $ctx = outboundPruneContext(free: true);
    $email = Email::query()->create([
        'inbox_id' => $ctx['inbox']->id,
        'message_id' => 'msg-'.bin2hex(random_bytes(4)).'@example.test',
        'sender_email' => 'origin@example.test',
        'recipient_email' => $ctx['inbox']->full_address,
        'received_at' => now(),
        'size_bytes' => 100,
        'processing_status' => 'received',
    ]);
    $attachment = Attachment::query()->create([
        'email_id' => $email->id,
        'original_filename' => 'report.pdf',
        'stored_filename' => 'opaque-'.bin2hex(random_bytes(4)),
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'checksum_sha256' => hash('sha256', bin2hex(random_bytes(8))),
        'storage_disk' => 'attachments',
        'storage_path' => 'outbound/'.$email->id.'/opaque',
        'scan_status' => AttachmentScanStatus::Clean,
        'is_safe' => true,
    ]);
    $message = makePruneMessage($ctx, [
        'created_at' => now()->subDays(2),
        'source_email_id' => $email->id,
        'attachment_ids' => [$attachment->id],
    ]);

    app(OutboundPruneService::class)->prune(false, true, 500);

    expect($message->fresh()->attachment_ids)->toBeNull()
        ->and(Attachment::query()->whereKey($attachment->id)->exists())->toBeTrue();
});

// --- Legal / security hold --------------------------------------------------

it('never redacts a message under an active retention hold', function (): void {
    $ctx = outboundPruneContext(free: true);
    $message = makePruneMessage($ctx, [
        'created_at' => now()->subDays(10),
        'retention_hold_reason_code' => 'legal_hold',
        'retention_hold_until' => null,
    ]);

    $report = app(OutboundPruneService::class)->prune(false, true, 500);

    expect($message->fresh()->content_redacted_at)->toBeNull()
        ->and($report['held'])->toBeGreaterThanOrEqual(1);
});

it('redacts once an expired (past) hold no longer applies', function (): void {
    $ctx = outboundPruneContext(free: true);
    $message = makePruneMessage($ctx, [
        'created_at' => now()->subDays(10),
        'retention_hold_reason_code' => 'legal_hold',
        'retention_hold_until' => now()->subDay(),
    ]);

    app(OutboundPruneService::class)->prune(false, true, 500);

    expect($message->fresh()->content_redacted_at)->not->toBeNull();
});

// --- Delivery attempts -------------------------------------------------------

it('prunes delivery attempts past attempt retention', function (): void {
    $ctx = outboundPruneContext(free: true);
    $message = makePruneMessage($ctx, ['created_at' => now()->subDays(200)]);
    $attempt = OutboundDeliveryAttempt::query()->create([
        'outbound_message_id' => $message->id,
        'attempt_number' => 1,
        'transport' => 'generic',
        'state' => OutboundDeliveryAttemptState::Accepted->value,
        'result' => 'accepted',
        'started_at' => now()->subDays(200),
        'completed_at' => now()->subDays(200),
    ]);
    $attempt->forceFill(['created_at' => now()->subDays(200)])->save();

    $report = app(OutboundPruneService::class)->prune(false, true, 500);

    expect($report['attempts_deleted'])->toBe(1)
        ->and(OutboundDeliveryAttempt::query()->whereKey($attempt->id)->exists())->toBeFalse();
});

it('never prunes a delivery attempt whose message is under an active hold', function (): void {
    $ctx = outboundPruneContext(free: true);
    $message = makePruneMessage($ctx, [
        'created_at' => now()->subDays(200),
        'retention_hold_reason_code' => 'legal_hold',
    ]);
    $attempt = OutboundDeliveryAttempt::query()->create([
        'outbound_message_id' => $message->id,
        'attempt_number' => 1,
        'transport' => 'generic',
        'state' => OutboundDeliveryAttemptState::Accepted->value,
        'result' => 'accepted',
        'started_at' => now()->subDays(200),
        'completed_at' => now()->subDays(200),
    ]);
    $attempt->forceFill(['created_at' => now()->subDays(200)])->save();

    $report = app(OutboundPruneService::class)->prune(false, true, 500);

    expect($report['attempts_deleted'])->toBe(0)
        ->and($report['held'])->toBeGreaterThanOrEqual(1)
        ->and(OutboundDeliveryAttempt::query()->whereKey($attempt->id)->exists())->toBeTrue();
});

// --- Provider events ----------------------------------------------------------

it('prunes provider events past event retention', function (): void {
    $ctx = outboundPruneContext(free: true);
    $message = makePruneMessage($ctx, ['created_at' => now()->subDays(200)]);
    $event = OutboundProviderEvent::query()->create([
        'provider' => 'generic',
        'provider_event_id' => 'evt-'.uniqid(),
        'provider_message_id' => '<prune@example.test>',
        'outbound_message_id' => $message->id,
        'event_type' => OutboundProviderEventType::Delivered,
        'normalized_status' => OutboundProviderEventType::Delivered->value,
        'received_at' => now()->subDays(200),
        'provider_event_at' => now()->subDays(200),
        'processed_at' => now()->subDays(200),
        'signature_state' => 'verified',
    ]);
    $event->forceFill(['created_at' => now()->subDays(200)])->save();

    $report = app(OutboundPruneService::class)->prune(false, true, 500);

    expect($report['provider_events_deleted'])->toBe(1)
        ->and(OutboundProviderEvent::query()->whereKey($event->id)->exists())->toBeFalse();
});

it('preserves recipient suppression rows regardless of provider event pruning', function (): void {
    $ctx = outboundPruneContext(free: true);
    $message = makePruneMessage($ctx, ['created_at' => now()->subDays(200)]);
    $event = OutboundProviderEvent::query()->create([
        'provider' => 'generic',
        'provider_event_id' => 'evt-complaint-'.uniqid(),
        'provider_message_id' => '<complaint@example.test>',
        'outbound_message_id' => $message->id,
        'event_type' => OutboundProviderEventType::Complained,
        'normalized_status' => OutboundProviderEventType::Complained->value,
        'received_at' => now()->subDays(200),
        'provider_event_at' => now()->subDays(200),
        'processed_at' => now()->subDays(200),
        'signature_state' => 'verified',
    ]);
    $event->forceFill(['created_at' => now()->subDays(200)])->save();

    $suppression = app(OutboundSuppressionService::class)->suppress(
        'complainer@example.test',
        'complaint',
        'provider_event',
        provider: 'generic',
        sourceEventId: (string) $event->id,
    );

    app(OutboundPruneService::class)->prune(false, true, 500);

    expect(OutboundProviderEvent::query()->whereKey($event->id)->exists())->toBeFalse()
        ->and(OutboundRecipientSuppression::query()->whereKey($suppression->id)->exists())->toBeTrue()
        ->and($suppression->fresh()->active)->toBeTrue();
});

// --- Hard delete --------------------------------------------------------------

it('hard-deletes a user-deleted, redacted, childless, terminal-state message', function (): void {
    $ctx = outboundPruneContext(free: true);
    $message = makePruneMessage($ctx, [
        'created_at' => now()->subDays(10),
        'state' => OutboundMessageState::Cancelled,
        'cancelled_at' => now()->subDays(9),
        'user_deleted_at' => now()->subDays(9),
    ]);

    $report = app(OutboundPruneService::class)->prune(false, true, 500);

    expect($report['messages_hard_deleted'])->toBe(1)
        ->and(OutboundMessage::query()->whereKey($message->id)->exists())->toBeFalse();
});

it('never hard-deletes a message that still has delivery-attempt children', function (): void {
    $ctx = outboundPruneContext(free: true);
    $message = makePruneMessage($ctx, [
        'created_at' => now()->subDays(10),
        'state' => OutboundMessageState::Cancelled,
        'cancelled_at' => now()->subDays(9),
        'user_deleted_at' => now()->subDays(9),
    ]);
    OutboundDeliveryAttempt::query()->create([
        'outbound_message_id' => $message->id,
        'attempt_number' => 1,
        'transport' => 'generic',
        'state' => OutboundDeliveryAttemptState::Accepted->value,
        'result' => 'accepted',
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    $report = app(OutboundPruneService::class)->prune(false, true, 500);

    expect($report['messages_hard_deleted'])->toBe(0)
        ->and(OutboundMessage::query()->whereKey($message->id)->exists())->toBeTrue();
});

it('never hard-deletes a message the user has not deleted', function (): void {
    $ctx = outboundPruneContext(free: true);
    $message = makePruneMessage($ctx, [
        'created_at' => now()->subDays(10),
        'state' => OutboundMessageState::Cancelled,
        'cancelled_at' => now()->subDays(9),
    ]);

    $report = app(OutboundPruneService::class)->prune(false, true, 500);

    expect($report['messages_hard_deleted'])->toBe(0)
        ->and(OutboundMessage::query()->whereKey($message->id)->exists())->toBeTrue();
});

it('never hard-deletes a sent message even if user-deleted, since it may still be delivered', function (): void {
    $ctx = outboundPruneContext(free: true);
    $message = makePruneMessage($ctx, [
        'created_at' => now()->subDays(10),
        'state' => OutboundMessageState::Sent,
        'user_deleted_at' => now()->subDays(9),
    ]);

    $report = app(OutboundPruneService::class)->prune(false, true, 500);

    expect($report['messages_hard_deleted'])->toBe(0)
        ->and(OutboundMessage::query()->whereKey($message->id)->exists())->toBeTrue();
});

// --- Bounded batch ------------------------------------------------------------

it('bounds redaction mutations to the requested batch size', function (): void {
    $ctx = outboundPruneContext(free: true);
    for ($i = 0; $i < 3; $i++) {
        makePruneMessage($ctx, ['created_at' => now()->subDays(5)]);
    }

    $report = app(OutboundPruneService::class)->prune(false, true, 1);

    expect($report['content_redacted'])->toBe(1)
        ->and(OutboundMessage::query()->whereNotNull('content_redacted_at')->count())->toBe(1);
});

it('rejects a confirmed batch size above the 1000 bound', function (): void {
    $ctx = outboundPruneContext(free: true);

    expect(fn () => app(OutboundPruneService::class)->prune(false, true, 1001))
        ->toThrow(InvalidArgumentException::class);
});

// --- Command: lock / idempotency / output ----------------------------------

it('is idempotent: re-running against already-pruned data changes nothing further', function (): void {
    $ctx = outboundPruneContext(free: true);
    $message = makePruneMessage($ctx, ['created_at' => now()->subDays(5)]);

    app(OutboundPruneService::class)->prune(false, true, 500);
    $secondReport = app(OutboundPruneService::class)->prune(false, true, 500);

    expect($secondReport['content_redacted'])->toBe(0)
        ->and($message->fresh()->content_redacted_at)->not->toBeNull();
});

it('prevents overlapping prune runs via a named cache lock', function (): void {
    $lock = Cache::lock('outbound:prune', 600);
    expect($lock->get())->toBeTrue();

    try {
        $exitCode = Artisan::call('outbound:prune', ['--confirm' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('in progress');
    } finally {
        $lock->release();
    }
});

it('never leaks a body, address, secret, or storage path in the command output', function (): void {
    $ctx = outboundPruneContext(free: true);
    makePruneMessage($ctx, [
        'created_at' => now()->subDays(5),
        'to_recipients' => ['leaky@example.test'],
        'subject' => 'Do not leak this subject',
        'text_body' => 'Do not leak this body either',
    ]);

    Artisan::call('outbound:prune', ['--confirm' => true]);
    $output = Artisan::output();

    expect($output)->not->toContain('leaky@example.test')
        ->and($output)->not->toContain('Do not leak this subject')
        ->and($output)->not->toContain('Do not leak this body')
        ->and($output)->not->toContain('/storage/')
        ->and($output)->not->toContain('outbound/');
});

it('reports operational metrics for every prune category', function (): void {
    $exitCode = Artisan::call('outbound:prune', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);

    foreach ([
        'eligible_content_redaction',
        'content_redacted',
        'eligible_attempts',
        'attempts_deleted',
        'eligible_provider_events',
        'provider_events_deleted',
        'eligible_hard_delete',
        'messages_hard_deleted',
        'held',
        'skipped',
        'failed',
        'blocked',
        'duration',
    ] as $key) {
        expect($output)->toContain($key.':');
    }
});

it('shows command help without error', function (): void {
    $exitCode = Artisan::call('outbound:prune', ['--help' => true]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('outbound:prune');
});

it('registers outbound:prune on the scheduler only when cleanup is enabled', function (): void {
    config(['outbound_retention.cleanup_enabled' => true]);

    Artisan::call('schedule:list');
    $output = Artisan::output();

    expect($output)->toContain('outbound:prune');
});
