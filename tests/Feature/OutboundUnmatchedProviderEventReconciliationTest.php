<?php

declare(strict_types=1);

use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Enums\OutboundProviderEventType;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\OutboundProviderEvent;
use App\Models\User;
use App\Services\Outbound\OutboundProviderEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function reconciliationSentOutboundMessage(string $providerMessageId): OutboundMessage
{
    $user = User::factory()->create();
    $domain = Domain::query()->create([
        'domain' => 'reconcile-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Reconcile',
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

    return OutboundMessage::query()->create([
        'user_id' => $user->id,
        'inbox_id' => $inbox->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Sent,
        'idempotency_key' => 'reconcile-'.uniqid(),
        'request_fingerprint' => hash('sha256', 'fp-'.uniqid()),
        'from_address' => $inbox->full_address,
        'to_recipients' => ['to@example.test'],
        'subject' => 'Race',
        'text_body' => 'Body',
        'provider' => 'generic',
        'provider_message_id' => $providerMessageId,
        'attempt_count' => 1,
        'sent_at' => now(),
    ]);
}

function unmatchedProviderEvent(array $overrides = []): OutboundProviderEvent
{
    return OutboundProviderEvent::query()->create(array_merge([
        'provider' => 'generic',
        'provider_event_id' => 'evt-'.uniqid(),
        'provider_message_id' => '<race@example.test>',
        'outbound_message_id' => null,
        'event_type' => OutboundProviderEventType::Delivered,
        'normalized_status' => OutboundProviderEventType::Delivered->value,
        'received_at' => now()->subMinutes(5),
        'provider_event_at' => now()->subMinutes(5),
        'processed_at' => now()->subMinutes(5),
        'signature_state' => 'verified',
    ], $overrides));
}

beforeEach(function (): void {
    config([
        'outbound.reconciliation.unmatched_event_window_hours' => 24,
        'outbound.reconciliation.unmatched_event_batch_size' => 50,
    ]);
});

it('re-correlates an unmatched event once the message becomes available and applies the transition', function (): void {
    $event = unmatchedProviderEvent();
    $message = reconciliationSentOutboundMessage('<race@example.test>');

    $summary = app(OutboundProviderEventProcessor::class)->reconcileUnmatched();

    expect($summary)->toBe(['evaluated' => 1, 'matched' => 1])
        ->and($event->fresh()->outbound_message_id)->toBe($message->getKey())
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Delivered)
        ->and($message->fresh()->delivered_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'outbound.provider_event_reconciled')->exists())->toBeTrue();
});

it('does not touch events that are still genuinely unmatched', function (): void {
    unmatchedProviderEvent(['provider_message_id' => '<never@example.test>']);

    $summary = app(OutboundProviderEventProcessor::class)->reconcileUnmatched();

    expect($summary)->toBe(['evaluated' => 1, 'matched' => 0]);
});

it('never re-evaluates events already matched to a message', function (): void {
    $message = reconciliationSentOutboundMessage('<already@example.test>');
    $event = unmatchedProviderEvent([
        'provider_message_id' => '<already@example.test>',
        'outbound_message_id' => $message->getKey(),
    ]);

    $summary = app(OutboundProviderEventProcessor::class)->reconcileUnmatched();

    expect($summary)->toBe(['evaluated' => 0, 'matched' => 0]);
    expect($event->fresh()->outbound_message_id)->toBe($message->getKey());
});

it('does not reconcile events outside the configured window', function (): void {
    config(['outbound.reconciliation.unmatched_event_window_hours' => 1]);
    unmatchedProviderEvent([
        'provider_message_id' => '<old@example.test>',
        'received_at' => now()->subHours(2),
    ]);
    reconciliationSentOutboundMessage('<old@example.test>');

    $summary = app(OutboundProviderEventProcessor::class)->reconcileUnmatched();

    expect($summary)->toBe(['evaluated' => 0, 'matched' => 0]);
});

it('never mutates ambiguous matches when multiple messages share a provider message id', function (): void {
    reconciliationSentOutboundMessage('<dup-race@example.test>');
    reconciliationSentOutboundMessage('<dup-race@example.test>');
    $event = unmatchedProviderEvent(['provider_message_id' => '<dup-race@example.test>']);

    $summary = app(OutboundProviderEventProcessor::class)->reconcileUnmatched();

    expect($summary)->toBe(['evaluated' => 1, 'matched' => 0])
        ->and($event->fresh()->outbound_message_id)->toBeNull();
});

it('bounds work to the configured batch size', function (): void {
    unmatchedProviderEvent();
    unmatchedProviderEvent();
    unmatchedProviderEvent();

    $summary = app(OutboundProviderEventProcessor::class)->reconcileUnmatched(limit: 2);

    expect($summary['evaluated'])->toBe(2);
});

it('runs via the scheduled command under a lock and prints a safe summary', function (): void {
    unmatchedProviderEvent(['provider_message_id' => '<cmd@example.test>']);
    reconciliationSentOutboundMessage('<cmd@example.test>');

    $exitCode = Artisan::call('outbound:reconcile-unmatched-events');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('matched: 1')
        ->and($output)->not->toContain('@example.test');
});
