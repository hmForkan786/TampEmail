<?php

declare(strict_types=1);

use App\Contracts\OutboundTransportInterface;
use App\DTOs\Outbound\OutboundDeliveryResult;
use App\Enums\BillingCycle;
use App\Enums\OutboundDeliveryAttemptState;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\Domain;
use App\Models\Feature;
use App\Models\Inbox;
use App\Models\OutboundDeliveryAttempt;
use App\Models\OutboundMessage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\FakeOutboundTransport;
use App\Services\Outbound\OutboundAttachmentSelector;
use App\Services\Outbound\OutboundAuthorizationService;
use App\Services\Outbound\OutboundDeliveryAttemptRecorder;
use App\Services\Outbound\OutboundLaunchControlService;
use App\Services\Outbound\OutboundStaleSendingReconciliationService;
use App\Services\Outbound\OutboundSuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{message: OutboundMessage, transport: FakeOutboundTransport}
 */
function deliveryAttemptContext(): array
{
    $user = User::factory()->create();
    $plan = Plan::query()->create([
        'slug' => 'attempt-'.uniqid(), 'name' => 'Attempt', 'price_monthly' => '0.00', 'price_yearly' => '0.00',
        'currency' => 'USD', 'is_free' => true, 'is_active' => true, 'display_order' => 1,
    ]);
    $feature = Feature::query()->firstOrCreate(
        ['key' => 'send_email'],
        ['name' => 'Send Email', 'value_type' => ValueType::Boolean, 'default_value' => ['enabled' => true], 'is_active' => true, 'display_order' => 10],
    );
    $plan->features()->syncWithoutDetaching([$feature->id => ['feature_value' => ['enabled' => true]]]);
    Subscription::query()->create([
        'user_id' => $user->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'billing_cycle' => BillingCycle::Monthly, 'starts_at' => now()->subDay(), 'auto_renew' => true,
        'price' => '0.00', 'currency' => 'USD',
    ]);
    $domain = Domain::query()->create([
        'domain' => 'attempt-'.bin2hex(random_bytes(3)).'.test', 'display_name' => 'Attempt',
        'is_active' => true, 'is_public' => true, 'allow_registration' => true, 'is_healthy' => true,
        'outbound_enabled' => true, 'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create([
        'domain_id' => $domain->id, 'user_id' => $user->id, 'local_part' => 'attempt',
        'full_address' => 'attempt@'.$domain->domain, 'inbox_type' => 'temporary', 'is_active' => true,
    ]);
    $message = OutboundMessage::query()->create([
        'user_id' => $user->id,
        'inbox_id' => $inbox->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Queued,
        'idempotency_key' => 'attempt-'.uniqid(),
        'request_fingerprint' => hash('sha256', 'fp-'.uniqid()),
        'from_address' => $inbox->full_address,
        'to_recipients' => ['to@example.test'],
        'subject' => 'Attempt tracking',
        'text_body' => 'Body',
        'attempt_count' => 0,
        'queued_at' => now(),
    ]);
    $transport = new FakeOutboundTransport(OutboundDeliveryResult::accepted('fake', 'attempt-1'));
    app()->instance(OutboundTransportInterface::class, $transport);

    return ['message' => $message, 'transport' => $transport];
}

function runDeliveryJob(OutboundMessage $message, FakeOutboundTransport $transport): void
{
    try {
        (new DeliverOutboundMessageJob($message->getKey()))->handle(
            $transport,
            app(OutboundAuthorizationService::class),
            app(AuditLogWriter::class),
            app(OutboundAttachmentSelector::class),
            app(OutboundSuppressionService::class),
            app(OutboundDeliveryAttemptRecorder::class),
            app(OutboundLaunchControlService::class),
        );
    } catch (RuntimeException) {
        // Expected when the job schedules a Laravel retry.
    }
}

beforeEach(function (): void {
    config([
        'outbound.enabled' => true,
        'outbound.send_enabled' => true,
        'outbound.transport' => 'array',
        'outbound.send_max_attempts' => 3,
        'outbound.rollout.mode' => 'enabled',
        'outbound.rollout.emergency_stop' => false,
    ]);
});

it('records one delivery attempt row per attempt, never overwriting prior attempts', function (): void {
    $ctx = deliveryAttemptContext();

    runDeliveryJob($ctx['message'], $ctx['transport']);

    $attempts = OutboundDeliveryAttempt::query()->where('outbound_message_id', $ctx['message']->getKey())->orderBy('attempt_number')->get();
    expect($attempts)->toHaveCount(1)
        ->and($attempts[0]->attempt_number)->toBe(1)
        ->and($attempts[0]->state)->toBe(OutboundDeliveryAttemptState::Accepted)
        ->and($attempts[0]->result)->toBe('accepted')
        ->and($attempts[0]->started_at)->not->toBeNull()
        ->and($attempts[0]->completed_at)->not->toBeNull()
        ->and($attempts[0]->duration_ms)->not->toBeNull();
});

it('appends a new attempt row for each retry instead of overwriting the previous attempt', function (): void {
    $ctx = deliveryAttemptContext();
    $ctx['transport']->setNextResult(OutboundDeliveryResult::temporaryFailure('smtp_4xx', 'Temporary'));

    runDeliveryJob($ctx['message'], $ctx['transport']);

    $ctx['message']->refresh();
    expect($ctx['message']->state)->toBe(OutboundMessageState::Queued);

    $ctx['transport']->setNextResult(OutboundDeliveryResult::accepted('fake', 'attempt-2'));
    runDeliveryJob($ctx['message'], $ctx['transport']);

    $attempts = OutboundDeliveryAttempt::query()
        ->where('outbound_message_id', $ctx['message']->getKey())
        ->orderBy('attempt_number')
        ->get();

    expect($attempts)->toHaveCount(2)
        ->and($attempts[0]->attempt_number)->toBe(1)
        ->and($attempts[0]->state)->toBe(OutboundDeliveryAttemptState::TemporaryFailure)
        ->and($attempts[0]->failure_category)->toBe('transport_temporary')
        ->and($attempts[1]->attempt_number)->toBe(2)
        ->and($attempts[1]->state)->toBe(OutboundDeliveryAttemptState::Accepted);
});

it('never stores body, full recipients, or raw transport responses on an attempt row', function (): void {
    $ctx = deliveryAttemptContext();

    runDeliveryJob($ctx['message'], $ctx['transport']);

    $attempt = OutboundDeliveryAttempt::query()->first();
    $columns = array_keys($attempt->getAttributes());

    expect($columns)->not->toContain('text_body')
        ->and($columns)->not->toContain('html_body')
        ->and($columns)->not->toContain('to_recipients')
        ->and($columns)->not->toContain('raw_response');
});

it('marks the in-flight attempt ambiguous rather than resending when the worker dies mid-transport', function (): void {
    $ctx = deliveryAttemptContext();
    $message = $ctx['message'];
    $message->forceFill([
        'state' => OutboundMessageState::Sending,
        'sending_at' => now()->subMinutes(20),
        'attempt_count' => 1,
        'transport_attempted_at' => now()->subMinutes(20),
    ])->save();

    app(OutboundDeliveryAttemptRecorder::class)->start($message, 'array');
    config(['outbound.reconciliation.stale_sending_threshold_seconds' => 900]);

    app(OutboundStaleSendingReconciliationService::class)->reconcile();

    $attempt = OutboundDeliveryAttempt::query()->where('outbound_message_id', $message->getKey())->first();
    expect($attempt->state)->toBe(OutboundDeliveryAttemptState::Ambiguous)
        ->and($attempt->ambiguous)->toBeTrue();
});

it('enforces state precedence: cancelled outranks every other state', function (): void {
    expect(OutboundMessageState::Cancelled->outranksOrEquals(OutboundMessageState::Delivered))->toBeTrue()
        ->and(OutboundMessageState::Cancelled->outranksOrEquals(OutboundMessageState::Failed))->toBeTrue()
        ->and(OutboundMessageState::Delivered->outranksOrEquals(OutboundMessageState::Cancelled))->toBeFalse();
});

it('enforces state precedence: delivered outranks failed and sent', function (): void {
    expect(OutboundMessageState::Delivered->outranksOrEquals(OutboundMessageState::Failed))->toBeTrue()
        ->and(OutboundMessageState::Delivered->outranksOrEquals(OutboundMessageState::Sent))->toBeTrue()
        ->and(OutboundMessageState::Failed->outranksOrEquals(OutboundMessageState::Delivered))->toBeFalse();
});

it('enforces state precedence: full documented ordering', function (): void {
    $ordered = [
        OutboundMessageState::Cancelled,
        OutboundMessageState::Delivered,
        OutboundMessageState::Failed,
        OutboundMessageState::Sent,
        OutboundMessageState::Sending,
        OutboundMessageState::Queued,
        OutboundMessageState::Scheduled,
        OutboundMessageState::Draft,
    ];

    foreach ($ordered as $i => $state) {
        foreach ($ordered as $j => $other) {
            expect($state->outranksOrEquals($other))->toBe($i <= $j);
        }
    }
});
