<?php

declare(strict_types=1);

use App\Contracts\OutboundTransportInterface;
use App\DTOs\Outbound\OutboundDeliveryResult;
use App\Enums\BillingCycle;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
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
use App\Services\Outbound\OutboundDeliveryAttemptRecorder;
use App\Services\Outbound\OutboundStaleSendingReconciliationService;
use App\Services\Outbound\OutboundSuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function staleSendingOutboundMessage(array $overrides = []): OutboundMessage
{
    $user = User::factory()->create();
    $domain = Domain::query()->create([
        'domain' => 'stale-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Stale',
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
        'state' => OutboundMessageState::Sending,
        'idempotency_key' => 'stale-'.uniqid(),
        'request_fingerprint' => hash('sha256', 'fp-'.uniqid()),
        'from_address' => $inbox->full_address,
        'to_recipients' => ['to@example.test'],
        'subject' => 'Stuck',
        'text_body' => 'Body',
        'attempt_count' => 1,
        'queued_at' => now()->subMinutes(20),
        'sending_at' => now()->subMinutes(20),
        'transport_attempted_at' => null,
    ], $overrides));
}

beforeEach(function (): void {
    config([
        'outbound.reconciliation.stale_sending_threshold_seconds' => 900,
        'outbound.send_max_attempts' => 3,
    ]);
});

it('safely requeues stale sending messages that never reached the transport', function (): void {
    Queue::fake();
    $message = staleSendingOutboundMessage();

    $summary = app(OutboundStaleSendingReconciliationService::class)->reconcile();

    expect($summary)->toBe(['evaluated' => 1, 'requeued' => 1, 'flagged_ambiguous' => 0, 'failed_exhausted' => 0, 'skipped' => 0])
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Queued)
        ->and(AuditLog::query()->where('action', 'outbound.stale_sending_requeued')->exists())->toBeTrue();

    Queue::assertPushed(DeliverOutboundMessageJob::class, fn (DeliverOutboundMessageJob $job): bool => $job->outboundMessageId === $message->getKey());
});

it('never requeues or fails an ambiguous message where the transport was already invoked', function (): void {
    Queue::fake();
    $message = staleSendingOutboundMessage(['transport_attempted_at' => now()->subMinutes(20)]);

    $summary = app(OutboundStaleSendingReconciliationService::class)->reconcile();

    expect($summary)->toBe(['evaluated' => 1, 'requeued' => 0, 'flagged_ambiguous' => 1, 'failed_exhausted' => 0, 'skipped' => 0]);

    $fresh = $message->fresh();
    expect($fresh->state)->toBe(OutboundMessageState::Sending)
        ->and($fresh->reconciliation_note)->toBe('ambiguous_transport_outcome')
        ->and($fresh->reconciliation_flagged_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'outbound.stale_sending_flagged_ambiguous')->exists())->toBeTrue();

    Queue::assertNotPushed(DeliverOutboundMessageJob::class);
});

it('does not repeatedly re-flag an already ambiguous message on subsequent runs', function (): void {
    $message = staleSendingOutboundMessage([
        'transport_attempted_at' => now()->subMinutes(20),
        'reconciliation_flagged_at' => now()->subMinutes(10),
        'reconciliation_note' => 'ambiguous_transport_outcome',
    ]);

    $summary = app(OutboundStaleSendingReconciliationService::class)->reconcile();

    expect($summary['flagged_ambiguous'])->toBe(0)
        ->and($summary['skipped'])->toBe(1)
        ->and(AuditLog::query()->where('action', 'outbound.stale_sending_flagged_ambiguous')->count())->toBe(0);

    expect($message->fresh()->state)->toBe(OutboundMessageState::Sending);
});

it('fails closed when the retry budget is already exhausted and the transport was never reached', function (): void {
    $message = staleSendingOutboundMessage(['attempt_count' => 3]);

    $summary = app(OutboundStaleSendingReconciliationService::class)->reconcile();

    expect($summary['failed_exhausted'])->toBe(1)
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Failed)
        ->and($message->fresh()->failure_code)->toBe('stale_sending_attempts_exhausted')
        ->and(AuditLog::query()->where('action', 'outbound.stale_sending_failed_exhausted')->exists())->toBeTrue();
});

it('ignores sending messages that are not yet stale', function (): void {
    $message = staleSendingOutboundMessage(['sending_at' => now()->subMinutes(2)]);

    $summary = app(OutboundStaleSendingReconciliationService::class)->reconcile();

    expect($summary['evaluated'])->toBe(0)
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Sending);
});

it('bounds work to the configured batch size', function (): void {
    staleSendingOutboundMessage();
    staleSendingOutboundMessage();
    staleSendingOutboundMessage();

    $summary = app(OutboundStaleSendingReconciliationService::class)->reconcile(limit: 2);

    expect($summary['evaluated'])->toBe(2);
});

it('records transport_attempted_at immediately before the transport is invoked', function (): void {
    config(['outbound.enabled' => true, 'outbound.send_enabled' => true, 'outbound.transport' => 'array']);
    $message = staleSendingOutboundMessage(['state' => OutboundMessageState::Queued, 'sending_at' => null]);

    $plan = Plan::query()->create([
        'slug' => 'stale-'.uniqid(), 'name' => 'Stale', 'price_monthly' => '0.00', 'price_yearly' => '0.00',
        'currency' => 'USD', 'is_free' => true, 'is_active' => true, 'display_order' => 1,
    ]);
    $feature = Feature::query()->firstOrCreate(
        ['key' => 'send_email'],
        ['name' => 'Send Email', 'value_type' => ValueType::Boolean, 'default_value' => ['enabled' => true], 'is_active' => true, 'display_order' => 10],
    );
    $plan->features()->syncWithoutDetaching([$feature->id => ['feature_value' => ['enabled' => true]]]);
    Subscription::query()->create([
        'user_id' => $message->user_id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly, 'starts_at' => now()->subDay(), 'auto_renew' => true,
        'price' => '0.00', 'currency' => 'USD',
    ]);

    $transport = new FakeOutboundTransport(OutboundDeliveryResult::accepted('fake', 'stale-1'));
    app()->instance(OutboundTransportInterface::class, $transport);

    (new DeliverOutboundMessageJob($message->getKey()))->handle(
        $transport,
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
        app(OutboundSuppressionService::class),
        app(OutboundDeliveryAttemptRecorder::class),
    );

    expect($message->fresh()->transport_attempted_at)->not->toBeNull()
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Sent);
});

it('runs via the scheduled command under a lock and prints a safe summary', function (): void {
    Queue::fake();
    staleSendingOutboundMessage();

    $exitCode = Artisan::call('outbound:reconcile-stale-sending');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('requeued: 1')
        ->and($output)->not->toContain('@example.test')
        ->and($output)->not->toContain('Stuck');
});
