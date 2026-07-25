<?php

declare(strict_types=1);

use App\DTOs\Outbound\OutboundDeliveryResult;
use App\DTOs\Outbound\OutboundProviderEventData;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Enums\OutboundProviderEventType;
use App\Exceptions\OutboundSendException;
use App\Jobs\DeliverOutboundMessageJob;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\OutboundRecipientSuppression;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\FakeOutboundTransport;
use App\Services\Outbound\OutboundAttachmentSelector;
use App\Services\Outbound\OutboundAuthorizationService;
use App\Services\Outbound\OutboundOpsService;
use App\Services\Outbound\OutboundProviderEventProcessor;
use App\Services\Outbound\OutboundSuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function suppressionContext(): array
{
    $user = User::factory()->create();
    $domain = Domain::query()->create([
        'domain' => 'sup-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Suppression',
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

    return compact('user', 'domain', 'inbox');
}

it('creates suppressions for permanent bounce and complaint but not temporary failure', function (): void {
    $ctx = suppressionContext();
    $message = OutboundMessage::query()->create([
        'user_id' => $ctx['user']->id,
        'inbox_id' => $ctx['inbox']->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Sent,
        'idempotency_key' => 'sup-bounce',
        'request_fingerprint' => hash('sha256', 'sup-bounce'),
        'from_address' => $ctx['inbox']->full_address,
        'to_recipients' => ['Bounce.Me@Example.TEST'],
        'subject' => 'x',
        'text_body' => 'y',
        'provider' => 'smtp',
        'provider_message_id' => '<sup-bounce@example.test>',
        'attempt_count' => 1,
        'sent_at' => now(),
    ]);

    $processor = app(OutboundProviderEventProcessor::class);
    $processor->ingest(new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'sup-evt-bounce',
        providerMessageId: '<sup-bounce@example.test>',
        eventType: OutboundProviderEventType::Bounced,
        providerEventAt: now(),
    ));

    $service = app(OutboundSuppressionService::class);
    expect($service->isSuppressed('bounce.me@example.test'))->toBeTrue()
        ->and(OutboundRecipientSuppression::query()->where('reason', 'permanent_bounce')->count())->toBe(1);

    // duplicate bounce does not duplicate suppression
    $processor->ingest(new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'sup-evt-bounce-2',
        providerMessageId: '<sup-bounce@example.test>',
        eventType: OutboundProviderEventType::Bounced,
        providerEventAt: now(),
    ));
    expect(OutboundRecipientSuppression::query()->where('reason', 'permanent_bounce')->where('active', true)->count())->toBe(1);

    $message->forceFill([
        'state' => OutboundMessageState::Sent,
        'provider_message_id' => '<sup-complaint@example.test>',
        'to_recipients' => ['complaint@example.test'],
        'idempotency_key' => 'sup-complaint',
        'request_fingerprint' => hash('sha256', 'sup-complaint'),
        'failed_at' => null,
        'failure_code' => null,
    ])->save();

    // need a fresh sent message for complaint path that still matches
    OutboundMessage::query()->create([
        'user_id' => $ctx['user']->id,
        'inbox_id' => $ctx['inbox']->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Sent,
        'idempotency_key' => 'sup-complaint-2',
        'request_fingerprint' => hash('sha256', 'sup-complaint-2'),
        'from_address' => $ctx['inbox']->full_address,
        'to_recipients' => ['complaint@example.test'],
        'subject' => 'x',
        'text_body' => 'y',
        'provider' => 'smtp',
        'provider_message_id' => '<sup-complaint@example.test>',
        'attempt_count' => 1,
        'sent_at' => now(),
    ]);

    $processor->ingest(new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'sup-evt-complaint',
        providerMessageId: '<sup-complaint@example.test>',
        eventType: OutboundProviderEventType::Complained,
        providerEventAt: now(),
    ));
    expect($service->isSuppressed('complaint@example.test'))->toBeTrue();

    OutboundMessage::query()->create([
        'user_id' => $ctx['user']->id,
        'inbox_id' => $ctx['inbox']->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Sent,
        'idempotency_key' => 'sup-temp',
        'request_fingerprint' => hash('sha256', 'sup-temp'),
        'from_address' => $ctx['inbox']->full_address,
        'to_recipients' => ['temp@example.test'],
        'subject' => 'x',
        'text_body' => 'y',
        'provider' => 'smtp',
        'provider_message_id' => '<sup-temp@example.test>',
        'attempt_count' => 1,
        'sent_at' => now(),
    ]);
    $processor->ingest(new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'sup-evt-temp',
        providerMessageId: '<sup-temp@example.test>',
        eventType: OutboundProviderEventType::TemporaryFailure,
        providerEventAt: now(),
    ));
    expect($service->isSuppressed('temp@example.test'))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'outbound.recipient_suppressed')->exists())->toBeTrue();
});

it('rejects suppressed to cc and bcc with a safe error', function (): void {
    config([
        'outbound.enabled' => true,
        'outbound.send_enabled' => true,
        'api.key_hash_secret' => 'suppression-test-secret',
    ]);

    $service = app(OutboundSuppressionService::class);
    $service->suppress('blocked@example.test', 'manual', 'admin');

    expect(fn () => $service->assertRecipientsAllowed(['ok@example.test', 'blocked@example.test']))
        ->toThrow(OutboundSendException::class);

    try {
        $service->assertRecipientsAllowed(['blocked@example.test'], User::factory()->create());
    } catch (OutboundSendException $exception) {
        expect($exception->errorCode)->toBe('recipient_suppressed')
            ->and($exception->getMessage())->not->toContain('provider')
            ->and(AuditLog::query()->where('action', 'outbound.send_blocked_by_suppression')->exists())->toBeTrue();
    }
});

it('rechecks suppression in the delivery job after queueing', function (): void {
    config([
        'outbound.enabled' => true,
        'outbound.send_enabled' => true,
    ]);

    $ctx = suppressionContext();
    $plan = App\Models\Plan::query()->create([
        'slug' => 'sup-'.uniqid(),
        'name' => 'Sup Plan',
        'price_monthly' => '0.00',
        'price_yearly' => '0.00',
        'currency' => 'USD',
        'is_free' => true,
        'is_active' => true,
        'display_order' => 1,
    ]);
    $feature = App\Models\Feature::query()->firstOrCreate(
        ['key' => 'send_email'],
        [
            'name' => 'Send Email',
            'value_type' => App\Enums\ValueType::Boolean,
            'default_value' => ['enabled' => true],
            'is_active' => true,
            'display_order' => 10,
        ],
    );
    $plan->features()->syncWithoutDetaching([
        $feature->id => ['feature_value' => ['enabled' => true]],
    ]);
    App\Models\Subscription::query()->create([
        'user_id' => $ctx['user']->id,
        'plan_id' => $plan->id,
        'status' => App\Enums\SubscriptionStatus::Active,
        'billing_cycle' => App\Enums\BillingCycle::Monthly,
        'starts_at' => now()->subDay(),
        'auto_renew' => true,
        'price' => '0.00',
        'currency' => 'USD',
    ]);

    $message = OutboundMessage::query()->create([
        'user_id' => $ctx['user']->id,
        'inbox_id' => $ctx['inbox']->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Queued,
        'idempotency_key' => 'sup-job',
        'request_fingerprint' => hash('sha256', 'sup-job'),
        'from_address' => $ctx['inbox']->full_address,
        'to_recipients' => ['later-blocked@example.test'],
        'subject' => 'x',
        'text_body' => 'y',
        'attempt_count' => 0,
        'queued_at' => now(),
    ]);

    app(OutboundSuppressionService::class)->suppress('later-blocked@example.test', 'manual', 'admin');

    $transport = new FakeOutboundTransport(OutboundDeliveryResult::accepted('smtp', 'should-not-send'));

    (new DeliverOutboundMessageJob((string) $message->getKey()))->handle(
        $transport,
        app(OutboundAuthorizationService::class),
        app(AuditLogWriter::class),
        app(OutboundAttachmentSelector::class),
        app(OutboundSuppressionService::class),
    );

    expect($message->fresh()->state)->toBe(OutboundMessageState::Failed)
        ->and($message->fresh()->failure_code)->toBe('recipient_suppressed')
        ->and($transport->sent)->toHaveCount(0);
});

it('allows expired suppressions and elevated admin unsuppress', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $service = app(OutboundSuppressionService::class);
    $row = $service->suppress(
        email: 'expire@example.test',
        reason: 'manual',
        source: 'admin',
        expiresAt: now()->subMinute(),
        actor: $admin,
    );

    expect($service->isSuppressed('expire@example.test'))->toBeFalse();

    $complaint = $service->suppress('complain@example.test', 'complaint', 'provider_event');
    expect(fn () => $service->unsuppress($complaint, User::factory()->create(), elevatedComplaintRemoval: false))
        ->toThrow(OutboundSendException::class);

    $service->unsuppress($complaint, $admin, elevatedComplaintRemoval: true);
    expect($service->isSuppressed('complain@example.test'))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'outbound.recipient_unsuppressed')->exists())->toBeTrue();

    $metrics = app(OutboundOpsService::class)->suppressionMetrics();
    expect($metrics)->toHaveKeys(['active', 'permanent_bounce', 'complaint', 'manual', 'blocked_sends_24h', 'added_24h', 'added_7d']);
});
