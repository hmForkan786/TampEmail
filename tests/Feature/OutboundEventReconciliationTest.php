<?php

declare(strict_types=1);

use App\Enums\OutboundDeliveryAttemptState;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\OutboundDeliveryAttempt;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Outbound\OutboundEventReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

function reconciliationServiceOutboundMessage(array $overrides = []): OutboundMessage
{
    $user = User::factory()->create();
    $domain = Domain::query()->create([
        'domain' => 'evtrec-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'EventReconciliation',
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

    return OutboundMessage::query()->create(array_merge([
        'user_id' => $user->id,
        'inbox_id' => $inbox->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Sent,
        'idempotency_key' => 'evtrec-'.uniqid(),
        'request_fingerprint' => hash('sha256', 'fp-'.uniqid()),
        'from_address' => $inbox->full_address,
        'to_recipients' => ['to@example.test'],
        'subject' => 'Reconcile me',
        'text_body' => 'Body',
        'provider' => 'generic',
        'attempt_count' => 1,
        'queued_at' => now()->subMinute(),
        'sending_at' => now()->subSeconds(30),
        'sent_at' => now()->subSeconds(10),
    ], $overrides));
}

beforeEach(function (): void {
    Cache::flush();
});

it('backfills a missing delivery attempt row for an otherwise settled message', function (): void {
    $message = reconciliationServiceOutboundMessage();
    expect(OutboundDeliveryAttempt::query()->count())->toBe(0);

    $summary = app(OutboundEventReconciliationService::class)->repairMissingDeliveryAttempts();

    expect($summary)->toBe(['evaluated' => 1, 'repaired' => 1]);

    $attempt = OutboundDeliveryAttempt::query()->where('outbound_message_id', $message->getKey())->first();
    expect($attempt)->not->toBeNull()
        ->and($attempt->attempt_number)->toBe(1)
        ->and($attempt->state)->toBe(OutboundDeliveryAttemptState::Accepted)
        ->and(AuditLog::query()->where('action', 'outbound.reconciliation_attempt_repaired')->exists())->toBeTrue();
});

it('never duplicates an attempt row that already exists', function (): void {
    $message = reconciliationServiceOutboundMessage();
    OutboundDeliveryAttempt::query()->create([
        'outbound_message_id' => $message->getKey(),
        'attempt_number' => 1,
        'transport' => 'generic',
        'state' => OutboundDeliveryAttemptState::Accepted->value,
        'result' => 'accepted',
        'started_at' => now()->subMinute(),
        'completed_at' => now(),
    ]);

    $summary = app(OutboundEventReconciliationService::class)->repairMissingDeliveryAttempts();

    expect($summary)->toBe(['evaluated' => 0, 'repaired' => 0])
        ->and(OutboundDeliveryAttempt::query()->where('outbound_message_id', $message->getKey())->count())->toBe(1);
});

it('records a safe permanent failure category when repairing a failed message attempt', function (): void {
    reconciliationServiceOutboundMessage([
        'state' => OutboundMessageState::Failed,
        'sent_at' => null,
        'failed_at' => now(),
        'failure_code' => 'invalid_recipient',
    ]);

    $summary = app(OutboundEventReconciliationService::class)->repairMissingDeliveryAttempts();

    expect($summary)->toBe(['evaluated' => 1, 'repaired' => 1]);

    $attempt = OutboundDeliveryAttempt::query()->first();
    expect($attempt->state)->toBe(OutboundDeliveryAttemptState::PermanentFailure)
        ->and($attempt->failure_category)->toBe('transport_permanent');
});

it('flags an impossible delivered-without-sent-timestamp combination for manual review', function (): void {
    $message = reconciliationServiceOutboundMessage([
        'state' => OutboundMessageState::Delivered,
        'sent_at' => null,
        'delivered_at' => now(),
    ]);

    $summary = app(OutboundEventReconciliationService::class)->flagImpossibleStates();

    expect($summary)->toBe(['evaluated' => 1, 'flagged' => 1]);

    $fresh = $message->fresh();
    expect($fresh->reconciliation_flagged_at)->not->toBeNull()
        ->and($fresh->reconciliation_note)->toBe('impossible_state_conflict')
        ->and(AuditLog::query()->where('action', 'outbound.reconciliation_impossible_state_detected')->exists())->toBeTrue();

    // Never auto-corrects: state and timestamps are left untouched.
    expect($fresh->state)->toBe(OutboundMessageState::Delivered)
        ->and($fresh->sent_at)->toBeNull();
});

it('never re-flags a message that has already been reviewed', function (): void {
    reconciliationServiceOutboundMessage([
        'state' => OutboundMessageState::Delivered,
        'sent_at' => null,
        'delivered_at' => now(),
        'reconciliation_flagged_at' => now()->subMinute(),
        'reconciliation_note' => 'impossible_state_conflict',
    ]);

    $summary = app(OutboundEventReconciliationService::class)->flagImpossibleStates();

    expect($summary)->toBe(['evaluated' => 0, 'flagged' => 0]);
});

it('does not flag consistent, normally settled messages', function (): void {
    reconciliationServiceOutboundMessage();
    reconciliationServiceOutboundMessage(['state' => OutboundMessageState::Delivered, 'delivered_at' => now()]);
    reconciliationServiceOutboundMessage(['state' => OutboundMessageState::Failed, 'sent_at' => null, 'failed_at' => now(), 'failure_code' => 'invalid_recipient']);

    $summary = app(OutboundEventReconciliationService::class)->flagImpossibleStates();

    expect($summary['flagged'])->toBe(0);
});

it('orchestrates every reconciliation phase and is idempotent on repeated runs', function (): void {
    $message = reconciliationServiceOutboundMessage();

    $first = app(OutboundEventReconciliationService::class)->reconcile();

    expect($first)->toHaveKeys([
        'unmatched_evaluated', 'unmatched_matched',
        'out_of_order_evaluated', 'out_of_order_resolved',
        'terminal_unmatched_evaluated', 'terminal_unmatched',
        'attempts_evaluated', 'attempts_repaired',
        'impossible_states_evaluated', 'impossible_states_flagged',
    ])->and($first['attempts_repaired'])->toBe(1);

    $second = app(OutboundEventReconciliationService::class)->reconcile();

    expect($second['attempts_repaired'])->toBe(0)
        ->and(OutboundDeliveryAttempt::query()->where('outbound_message_id', $message->getKey())->count())->toBe(1);
});

it('runs via the outbound:reconcile-events command under a lock with a safe summary', function (): void {
    reconciliationServiceOutboundMessage();

    $exitCode = Artisan::call('outbound:reconcile-events');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('attempts_repaired: 1')
        ->and($output)->not->toContain('@example.test')
        ->and($output)->not->toContain('Reconcile me');
});

it('exposes command help without side effects', function (): void {
    $exitCode = Artisan::call('outbound:reconcile-events', ['--help' => true]);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('outbound:reconcile-events');
});
