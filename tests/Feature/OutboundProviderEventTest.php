<?php

declare(strict_types=1);

use App\DTOs\Outbound\OutboundProviderEventData;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Enums\OutboundProviderEventType;
use App\Jobs\ProcessOutboundProviderEventJob;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\OutboundProviderEvent;
use App\Models\User;
use App\Services\Outbound\OutboundOpsService;
use App\Services\Outbound\OutboundProviderEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function outboundWebhookHeaders(string $body, array $headers = []): array
{
    $provider = 'generic';
    $timestamp = (string) time();
    $secret = 'outbound-webhook-secret';

    return array_merge([
        'X-Outbound-Timestamp' => $timestamp,
        'X-Outbound-Signature' => hash_hmac('sha256', $provider.'.'.$timestamp.'.'.$body, $secret),
        'Content-Type' => 'application/json',
    ], $headers);
}

function seedSentOutboundMessage(string $providerMessageId = '<msg-1@example.test>'): OutboundMessage
{
    $user = User::factory()->create();
    $domain = Domain::query()->create([
        'domain' => 'prov-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Provider',
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
        'idempotency_key' => 'prov-'.uniqid(),
        'request_fingerprint' => hash('sha256', 'fp-'.uniqid()),
        'from_address' => $inbox->full_address,
        'to_recipients' => ['to@example.test'],
        'subject' => 'Hello',
        'text_body' => 'Body',
        'provider' => 'smtp',
        'provider_message_id' => $providerMessageId,
        'attempt_count' => 1,
        'queued_at' => now()->subMinute(),
        'sending_at' => now()->subSeconds(30),
        'sent_at' => now()->subSeconds(10),
    ]);
}

beforeEach(function (): void {
    config([
        'outbound.delivery_webhook.providers.generic.secret' => 'outbound-webhook-secret',
        'outbound.delivery_webhook.max_body_bytes' => 2000,
        'outbound.delivery_webhook.timestamp_skew_seconds' => 300,
        'queue.default' => 'sync',
    ]);
    Cache::flush();
});

it('accepts a valid signed provider event without exposing raw payload', function (): void {
    Queue::fake();
    $payload = [
        'event_id' => 'evt-1',
        'provider_message_id' => '<msg-1@example.test>',
        'event_type' => 'delivered',
        'occurred_at' => now()->toIso8601String(),
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $response = $this->withHeaders(outboundWebhookHeaders($body))
        ->postJson('/api/v1/webhooks/outbound/generic', $payload);

    $response->assertStatus(202)
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.provider_event_id', 'evt-1');
    expect($response->getContent())->not->toContain('outbound-webhook-secret');
    Queue::assertPushed(ProcessOutboundProviderEventJob::class);
});

it('rejects invalid missing and stale signatures', function (): void {
    $payload = ['event_id' => 'evt-2', 'event_type' => 'delivered'];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->withHeaders(outboundWebhookHeaders($body, [
        'X-Outbound-Signature' => 'bad',
    ]))->postJson('/api/v1/webhooks/outbound/generic', $payload)->assertUnauthorized();

    $this->withHeaders([
        'Content-Type' => 'application/json',
        'X-Outbound-Timestamp' => (string) time(),
    ])->postJson('/api/v1/webhooks/outbound/generic', $payload)->assertUnauthorized();

    $staleHeaders = outboundWebhookHeaders($body);
    $staleHeaders['X-Outbound-Timestamp'] = (string) (time() - 1000);
    $staleHeaders['X-Outbound-Signature'] = hash_hmac(
        'sha256',
        'generic.'.$staleHeaders['X-Outbound-Timestamp'].'.'.$body,
        'outbound-webhook-secret',
    );
    $this->withHeaders($staleHeaders)->postJson('/api/v1/webhooks/outbound/generic', $payload)->assertUnauthorized();
});

it('rejects unsupported providers and does not leak secrets', function (): void {
    $payload = ['event_id' => 'evt-3', 'event_type' => 'delivered'];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $response = $this->withHeaders(outboundWebhookHeaders($body))
        ->postJson('/api/v1/webhooks/outbound/unknown', $payload);
    $response->assertNotFound();
    expect($response->getContent())->not->toContain('outbound-webhook-secret');
});

it('marks sent messages delivered from trusted delivered events', function (): void {
    $message = seedSentOutboundMessage('<deliver-me@example.test>');
    $data = new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'evt-delivered-1',
        providerMessageId: '<deliver-me@example.test>',
        eventType: OutboundProviderEventType::Delivered,
        providerEventAt: now(),
    );

    $result = app(OutboundProviderEventProcessor::class)->ingest($data);

    expect($result['outcome'])->toBe('delivered')
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Delivered)
        ->and($message->fresh()->delivered_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'outbound.delivery_confirmed')->exists())->toBeTrue();
});

it('does not mark delivered from accepted events', function (): void {
    $message = seedSentOutboundMessage('<accepted-only@example.test>');
    $data = new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'evt-accepted-1',
        providerMessageId: '<accepted-only@example.test>',
        eventType: OutboundProviderEventType::Accepted,
        providerEventAt: now(),
    );

    $result = app(OutboundProviderEventProcessor::class)->ingest($data);
    expect($result['outcome'])->toBe('ignored')
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Sent);
});

it('handles duplicate events idempotently', function (): void {
    $message = seedSentOutboundMessage('<dup@example.test>');
    $data = new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'evt-dup-1',
        providerMessageId: '<dup@example.test>',
        eventType: OutboundProviderEventType::Delivered,
        providerEventAt: now(),
    );

    $first = app(OutboundProviderEventProcessor::class)->ingest($data);
    $second = app(OutboundProviderEventProcessor::class)->ingest($data);

    expect($first['duplicate'])->toBeFalse()
        ->and($second['duplicate'])->toBeTrue()
        ->and(OutboundProviderEvent::query()->count())->toBe(1)
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Delivered);
});

it('does not overwrite delivered with temporary failure', function (): void {
    $message = seedSentOutboundMessage('<order@example.test>');
    $processor = app(OutboundProviderEventProcessor::class);

    $processor->ingest(new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'evt-order-delivered',
        providerMessageId: '<order@example.test>',
        eventType: OutboundProviderEventType::Delivered,
        providerEventAt: now()->subMinute(),
    ));

    $result = $processor->ingest(new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'evt-order-temp',
        providerMessageId: '<order@example.test>',
        eventType: OutboundProviderEventType::TemporaryFailure,
        providerEventAt: now(),
    ));

    expect($result['outcome'])->toBe('ignored')
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Delivered);
});

it('marks permanent bounce as failed when not delivered', function (): void {
    $message = seedSentOutboundMessage('<bounce@example.test>');
    $result = app(OutboundProviderEventProcessor::class)->ingest(new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'evt-bounce-1',
        providerMessageId: '<bounce@example.test>',
        eventType: OutboundProviderEventType::Bounced,
        providerEventAt: now(),
    ));

    expect($result['outcome'])->toBe('failed')
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Failed)
        ->and($message->fresh()->failure_code)->toBe('provider_bounce')
        ->and(AuditLog::query()->where('action', 'outbound.bounce_received')->exists())->toBeTrue();
});

it('records complaints without changing delivered state', function (): void {
    $message = seedSentOutboundMessage('<complaint@example.test>');
    $processor = app(OutboundProviderEventProcessor::class);
    $processor->ingest(new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'evt-complaint-d',
        providerMessageId: '<complaint@example.test>',
        eventType: OutboundProviderEventType::Delivered,
        providerEventAt: now()->subMinute(),
    ));

    $result = $processor->ingest(new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'evt-complaint-c',
        providerMessageId: '<complaint@example.test>',
        eventType: OutboundProviderEventType::Complained,
        providerEventAt: now(),
    ));

    expect($result['outcome'])->toBe('complaint_recorded')
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Delivered)
        ->and(AuditLog::query()->where('action', 'outbound.complaint_received')->exists())->toBeTrue();
});

it('never delivers cancelled messages and stores unmatched events', function (): void {
    $message = seedSentOutboundMessage('<cancel@example.test>');
    $message->forceFill([
        'state' => OutboundMessageState::Cancelled,
        'cancelled_at' => now(),
    ])->save();

    $cancelled = app(OutboundProviderEventProcessor::class)->ingest(new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'evt-cancel',
        providerMessageId: '<cancel@example.test>',
        eventType: OutboundProviderEventType::Delivered,
        providerEventAt: now(),
    ));
    expect($cancelled['outcome'])->toBe('ignored_cancelled')
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Cancelled);

    $unmatched = app(OutboundProviderEventProcessor::class)->ingest(new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'evt-unmatched',
        providerMessageId: '<missing@example.test>',
        eventType: OutboundProviderEventType::Delivered,
        providerEventAt: now(),
    ));
    expect($unmatched['outcome'])->toBe('unmatched')
        ->and(OutboundProviderEvent::query()->where('provider_event_id', 'evt-unmatched')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'outbound.provider_event_unmatched')->exists())->toBeTrue();
});

it('resolves an out-of-order delivered event once the message reaches sent', function (): void {
    $user = User::factory()->create();
    $domain = Domain::query()->create([
        'domain' => 'ooo-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'OutOfOrder',
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
    $message = OutboundMessage::query()->create([
        'user_id' => $user->id,
        'inbox_id' => $inbox->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Sending,
        'idempotency_key' => 'ooo-'.uniqid(),
        'request_fingerprint' => hash('sha256', 'fp-'.uniqid()),
        'from_address' => $inbox->full_address,
        'to_recipients' => ['to@example.test'],
        'subject' => 'Race',
        'text_body' => 'Body',
        'provider' => 'generic',
        'provider_message_id' => '<ooo@example.test>',
        'attempt_count' => 1,
    ]);

    $result = app(OutboundProviderEventProcessor::class)->ingest(new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'evt-ooo-1',
        providerMessageId: '<ooo@example.test>',
        eventType: OutboundProviderEventType::Delivered,
        providerEventAt: now(),
    ));

    expect($result['outcome'])->toBe('ignored_state')
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Sending);

    $message->forceFill(['state' => OutboundMessageState::Sent, 'sent_at' => now()])->save();

    $summary = app(OutboundProviderEventProcessor::class)->reconcileOutOfOrder();

    expect($summary)->toBe(['evaluated' => 1, 'resolved' => 1])
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Delivered)
        ->and(AuditLog::query()->where('action', 'outbound.provider_event_out_of_order_resolved')->exists())->toBeTrue();
});

it('stops retrying an out-of-order event once it exceeds the attempt cap', function (): void {
    config(['outbound.reconciliation.out_of_order_max_attempts' => 1]);
    $user = User::factory()->create();
    $domain = Domain::query()->create([
        'domain' => 'cap-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Capped',
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
    $message = OutboundMessage::query()->create([
        'user_id' => $user->id,
        'inbox_id' => $inbox->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Sending,
        'idempotency_key' => 'cap-'.uniqid(),
        'request_fingerprint' => hash('sha256', 'fp-'.uniqid()),
        'from_address' => $inbox->full_address,
        'to_recipients' => ['to@example.test'],
        'subject' => 'Never resolves',
        'text_body' => 'Body',
        'provider' => 'generic',
        'provider_message_id' => '<capped@example.test>',
        'attempt_count' => 1,
    ]);

    app(OutboundProviderEventProcessor::class)->ingest(new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'evt-capped',
        providerMessageId: '<capped@example.test>',
        eventType: OutboundProviderEventType::Delivered,
        providerEventAt: now(),
    ));

    $first = app(OutboundProviderEventProcessor::class)->reconcileOutOfOrder();
    expect($first)->toBe(['evaluated' => 1, 'resolved' => 0])
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Sending);

    $second = app(OutboundProviderEventProcessor::class)->reconcileOutOfOrder();
    expect($second)->toBe(['evaluated' => 0, 'resolved' => 0]);
});

it('marks unmatched events terminal once they age out of the correlation window', function (): void {
    $event = OutboundProviderEvent::query()->create([
        'provider' => 'generic',
        'provider_event_id' => 'evt-expired',
        'provider_message_id' => '<expired@example.test>',
        'outbound_message_id' => null,
        'event_type' => OutboundProviderEventType::Delivered,
        'normalized_status' => OutboundProviderEventType::Delivered->value,
        'received_at' => now()->subHours(48),
        'provider_event_at' => now()->subHours(48),
        'processed_at' => now()->subHours(48),
        'signature_state' => 'verified',
    ]);

    $summary = app(OutboundProviderEventProcessor::class)->finalizeExpiredUnmatched();

    expect($summary)->toBe(['evaluated' => 1, 'terminal' => 1])
        ->and($event->fresh()->terminal_unmatched_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'outbound.provider_event_terminal_unmatched')->exists())->toBeTrue();

    $again = app(OutboundProviderEventProcessor::class)->finalizeExpiredUnmatched();
    expect($again)->toBe(['evaluated' => 0, 'terminal' => 0]);
});

it('updates operations metrics for provider events', function (): void {
    seedSentOutboundMessage('<ops@example.test>');
    app(OutboundProviderEventProcessor::class)->ingest(new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'evt-ops',
        providerMessageId: '<ops@example.test>',
        eventType: OutboundProviderEventType::Delivered,
        providerEventAt: now(),
    ));

    $report = app(OutboundOpsService::class)->report();
    expect($report['volume']['last_24_hours'])->toHaveKey('delivered')
        ->and($report['provider'])->toHaveKeys(['delivered', 'bounced', 'complained', 'unmatched_provider_events']);
});
