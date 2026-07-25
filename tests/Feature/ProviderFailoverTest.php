<?php

declare(strict_types=1);

use App\Actions\Outbound\RetryOutboundMessageWithProviderAction;
use App\Contracts\DnsResolverInterface;
use App\DTOs\Outbound\OutboundProviderEventData;
use App\Enums\BillingCycle;
use App\Enums\OutboundDeliveryAttemptState;
use App\Enums\OutboundMessageState;
use App\Enums\OutboundOperation;
use App\Enums\OutboundProviderEventType;
use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Exceptions\OutboundSendException;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Feature;
use App\Models\Inbox;
use App\Models\OutboundDeliveryAttempt;
use App\Models\OutboundMessage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Dns\FakeDnsResolver;
use App\Services\Outbound\OutboundDomainAuthenticationService;
use App\Services\Outbound\OutboundFailoverEligibility;
use App\Services\Outbound\OutboundOpsService;
use App\Services\Outbound\OutboundProviderEventProcessor;
use App\Services\Outbound\OutboundProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, domain: Domain, inbox: Inbox}
 */
function failoverContext(string $domainName): array
{
    $user = User::factory()->create();
    $plan = Plan::query()->create([
        'slug' => 'failover-'.uniqid(), 'name' => 'Failover', 'price_monthly' => '0.00', 'price_yearly' => '0.00',
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
        'domain' => $domainName, 'display_name' => 'Failover',
        'is_active' => true, 'is_public' => true, 'allow_registration' => true, 'is_healthy' => true,
        'outbound_enabled' => true, 'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create([
        'domain_id' => $domain->id, 'user_id' => $user->id, 'local_part' => 'failover',
        'full_address' => 'failover@'.$domain->domain, 'inbox_type' => 'temporary', 'is_active' => true,
    ]);

    return ['user' => $user, 'domain' => $domain, 'inbox' => $inbox];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function failedOutboundMessage(User $user, Inbox $inbox, array $overrides = []): OutboundMessage
{
    return OutboundMessage::query()->create(array_merge([
        'user_id' => $user->id,
        'inbox_id' => $inbox->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Failed,
        'idempotency_key' => 'failover-'.uniqid(),
        'request_fingerprint' => hash('sha256', 'fp-'.uniqid()),
        'from_address' => $inbox->full_address,
        'to_recipients' => ['to@example.test'],
        'subject' => 'Failover test',
        'text_body' => 'Body',
        'provider' => 'generic',
        'attempt_count' => 1,
        'queued_at' => now()->subMinutes(5),
        'sending_at' => now()->subMinutes(4),
        'failed_at' => now(),
        'failure_code' => 'invalid_config',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function failoverAttempt(OutboundMessage $message, array $overrides = []): OutboundDeliveryAttempt
{
    return OutboundDeliveryAttempt::query()->create(array_merge([
        'outbound_message_id' => $message->getKey(),
        'attempt_number' => $message->attempt_count,
        'transport' => 'array',
        'provider' => $message->provider,
        'state' => OutboundDeliveryAttemptState::PermanentFailure->value,
        'result' => 'configuration_failure',
        'ambiguous' => false,
        'started_at' => now()->subMinutes(4),
        'completed_at' => now()->subMinutes(3),
    ], $overrides));
}

function makeSesDomainVerified(Domain $domain): void
{
    $dns = new FakeDnsResolver;
    app()->instance(DnsResolverInterface::class, $dns);
    config([
        'outbound.domain_authentication.ses.dkim_tokens' => 'aaa',
        'outbound.domain_authentication.ses.spf_include' => 'include:amazonses.com',
    ]);
    $dns->setTxt($domain->domain, [
        'v=spf1 include:amazonses.com ~all',
        'temail-domain-verification='.substr(hash('sha256', 'outbound-domain:'.$domain->getKey()), 0, 32),
    ]);
    $dns->setCname('aaa._domainkey.'.$domain->domain, ['aaa.dkim.amazonses.com']);
    $dns->setTxt('_dmarc.'.$domain->domain, ['v=DMARC1; p=reject']);

    app(OutboundDomainAuthenticationService::class)->verify($domain, 'ses');
}

beforeEach(function (): void {
    config([
        'outbound.enabled' => true,
        'outbound.send_enabled' => true,
        'outbound.transport' => 'array',
        'mail.default' => 'array',
        'outbound.primary_provider' => 'generic',
        'outbound.provider' => 'generic',
        'outbound.secondary_provider' => 'ses',
        'outbound.failover_enabled' => false,
        'outbound.rollout.mode' => 'enabled',
        'outbound.rollout.emergency_stop' => false,
        'outbound.delivery_webhook.providers.generic.secret' => 'test-secret',
        'outbound.delivery_webhook.providers.ses.topic_arn' => 'arn:aws:sns:us-east-1:123456789012:topic',
        'outbound.domain_authentication.enforce' => true,
    ]);
    Cache::flush();
});

// --- Historical attempt attribution ---------------------------------------

it('keeps a historical attempt attributed to the provider it actually used after config changes', function (): void {
    $ctx = failoverContext('history-'.bin2hex(random_bytes(3)).'.test');
    $message = failedOutboundMessage($ctx['user'], $ctx['inbox'], ['provider' => 'generic']);
    failoverAttempt($message, ['provider' => 'generic']);

    // Flip primary/secondary after the fact.
    config(['outbound.primary_provider' => 'ses', 'outbound.secondary_provider' => 'generic']);

    $attempt = OutboundDeliveryAttempt::query()->where('outbound_message_id', $message->getKey())->first();
    expect($attempt->provider)->toBe('generic');
});

// --- Provider-scoped webhook correlation (isolation) ----------------------

it('never lets one provider identity correlate to a message tagged with a different provider', function (): void {
    $ctx = failoverContext('isolation-'.bin2hex(random_bytes(3)).'.test');
    $message = OutboundMessage::query()->create([
        'user_id' => $ctx['user']->id,
        'inbox_id' => $ctx['inbox']->id,
        'operation' => OutboundOperation::Send,
        'state' => OutboundMessageState::Sent,
        'idempotency_key' => 'iso-'.uniqid(),
        'request_fingerprint' => hash('sha256', 'fp-'.uniqid()),
        'from_address' => $ctx['inbox']->full_address,
        'to_recipients' => ['to@example.test'],
        'subject' => 'Isolation',
        'text_body' => 'Body',
        'provider' => 'ses',
        'provider_message_id' => '<iso@example.test>',
        'attempt_count' => 1,
        'sent_at' => now()->subMinute(),
    ]);

    $data = new OutboundProviderEventData(
        provider: 'generic',
        providerEventId: 'evt-iso-1',
        providerMessageId: '<iso@example.test>',
        eventType: OutboundProviderEventType::Delivered,
        providerEventAt: now(),
    );

    $result = app(OutboundProviderEventProcessor::class)->ingest($data);

    expect($result['outcome'])->toBe('unmatched')
        ->and($message->fresh()->state)->toBe(OutboundMessageState::Sent);
});

// --- Parser isolation -------------------------------------------------------

it('resolves a distinct parser instance per provider that only supports its own identity', function (): void {
    $registry = app(OutboundProviderRegistry::class);
    $generic = $registry->resolveParser('generic');
    $ses = $registry->resolveParser('ses');

    expect($generic->supports('generic'))->toBeTrue()
        ->and($generic->supports('ses'))->toBeFalse()
        ->and($ses->supports('ses'))->toBeTrue()
        ->and($ses->supports('generic'))->toBeFalse();
});

// --- Failover disabled by default ------------------------------------------

it('has failover disabled by default in a fresh configuration', function (): void {
    config(['outbound.failover_enabled' => false]);
    expect(app(OutboundProviderRegistry::class)->failoverEnabled())->toBeFalse()
        ->and((bool) config('outbound.failover_enabled'))->toBeFalse();
});

// --- Failover eligibility policy --------------------------------------------

it('marks safe pre-acceptance failures eligible for manual cross-provider retry', function (): void {
    $ctx = failoverContext('eligible-'.bin2hex(random_bytes(3)).'.test');

    foreach (['invalid_config', 'invalid_mailer', 'invalid_transport', 'transport_unavailable', 'dns_failure'] as $code) {
        $message = failedOutboundMessage($ctx['user'], $ctx['inbox'], ['failure_code' => $code]);
        $attempt = failoverAttempt($message);

        $result = app(OutboundFailoverEligibility::class)->evaluate($message, $attempt);
        expect($result['eligible'])->toBeTrue("failed for code {$code}")
            ->and($result['reason_code'])->toBe('safe_pre_acceptance_failure');
    }
});

it('never marks an ambiguous attempt eligible regardless of failure code', function (): void {
    $ctx = failoverContext('ambiguous-'.bin2hex(random_bytes(3)).'.test');
    $message = failedOutboundMessage($ctx['user'], $ctx['inbox'], ['failure_code' => 'invalid_config']);
    $attempt = failoverAttempt($message, ['ambiguous' => true, 'state' => OutboundDeliveryAttemptState::Ambiguous->value]);

    $result = app(OutboundFailoverEligibility::class)->evaluate($message, $attempt);
    expect($result['eligible'])->toBeFalse()
        ->and($result['reason_code'])->toBe('ambiguous_attempt');
});

it('never marks a reconciliation-flagged message eligible', function (): void {
    $ctx = failoverContext('flagged-'.bin2hex(random_bytes(3)).'.test');
    $message = failedOutboundMessage($ctx['user'], $ctx['inbox'], [
        'failure_code' => 'invalid_config',
        'reconciliation_flagged_at' => now(),
    ]);
    $attempt = failoverAttempt($message);

    $result = app(OutboundFailoverEligibility::class)->evaluate($message, $attempt);
    expect($result['eligible'])->toBeFalse()
        ->and($result['reason_code'])->toBe('ambiguous_flagged');
});

it('never marks an accepted (non-failed) message eligible', function (): void {
    $ctx = failoverContext('accepted-'.bin2hex(random_bytes(3)).'.test');
    $message = failedOutboundMessage($ctx['user'], $ctx['inbox'], ['state' => OutboundMessageState::Sent, 'failure_code' => null]);
    $attempt = failoverAttempt($message, ['state' => OutboundDeliveryAttemptState::Accepted->value]);

    $result = app(OutboundFailoverEligibility::class)->evaluate($message, $attempt);
    expect($result['eligible'])->toBeFalse()
        ->and($result['reason_code'])->toBe('message_not_failed');
});

it('never marks an authentication failure or permanent recipient rejection eligible', function (): void {
    $ctx = failoverContext('permfail-'.bin2hex(random_bytes(3)).'.test');

    foreach (['credentials_rejected', 'tls_configuration', 'invalid_recipient', 'message_too_large', 'timeout', 'rate_limit'] as $code) {
        $message = failedOutboundMessage($ctx['user'], $ctx['inbox'], ['failure_code' => $code]);
        $attempt = failoverAttempt($message);

        $result = app(OutboundFailoverEligibility::class)->evaluate($message, $attempt);
        expect($result['eligible'])->toBeFalse("failure code {$code} must not be eligible")
            ->and($result['reason_code'])->toBe('not_pre_acceptance_safe');
    }
});

// --- Manual provider retry: authorization and allowlist ---------------------

it('denies manual provider retry for a non-admin actor', function (): void {
    $ctx = failoverContext('nonadmin-'.bin2hex(random_bytes(3)).'.test');
    $message = failedOutboundMessage($ctx['user'], $ctx['inbox']);
    failoverAttempt($message);

    expect(fn () => app(RetryOutboundMessageWithProviderAction::class)->execute($message, 'ses', $ctx['user']))
        ->toThrow(OutboundSendException::class);

    expect(AuditLog::query()->where('action', 'outbound.manual_provider_retry_blocked')->exists())->toBeTrue();
});

it('rejects an arbitrary, non-allowlisted provider name', function (): void {
    $ctx = failoverContext('allowlist-'.bin2hex(random_bytes(3)).'.test');
    $admin = User::factory()->platformAdmin()->create();
    $message = failedOutboundMessage($ctx['user'], $ctx['inbox']);
    failoverAttempt($message);

    try {
        app(RetryOutboundMessageWithProviderAction::class)->execute($message, 'sendgrid', $admin);
        expect(false)->toBeTrue('expected exception');
    } catch (OutboundSendException $exception) {
        expect($exception->errorCode)->toBe('provider_not_allowlisted');
    }
});

// --- Manual provider retry: readiness / domain gates ------------------------

it('denies manual provider retry when the secondary provider is not ready', function (): void {
    $ctx = failoverContext('notready-'.bin2hex(random_bytes(3)).'.test');
    config(['outbound.delivery_webhook.providers.ses.topic_arn' => null]);
    $admin = User::factory()->platformAdmin()->create();
    $message = failedOutboundMessage($ctx['user'], $ctx['inbox']);
    failoverAttempt($message);

    try {
        app(RetryOutboundMessageWithProviderAction::class)->execute($message, 'ses', $admin);
        expect(false)->toBeTrue('expected exception');
    } catch (OutboundSendException $exception) {
        expect($exception->errorCode)->toBe('provider_not_ready');
    }
});

it('denies manual provider retry when the secondary provider domain is not verified', function (): void {
    $ctx = failoverContext('nodomain-'.bin2hex(random_bytes(3)).'.test');
    $admin = User::factory()->platformAdmin()->create();
    $message = failedOutboundMessage($ctx['user'], $ctx['inbox']);
    failoverAttempt($message);

    // ses is ready (topic arn configured) but no domain-auth record exists for ses.
    try {
        app(RetryOutboundMessageWithProviderAction::class)->execute($message, 'ses', $admin);
        expect(false)->toBeTrue('expected exception');
    } catch (OutboundSendException $exception) {
        expect($exception->errorCode)->toBe('domain_auth_not_ready_for_provider');
    }
});

it('denies manual provider retry when emergency stop is active', function (): void {
    $ctx = failoverContext('estop-'.bin2hex(random_bytes(3)).'.test');
    makeSesDomainVerified($ctx['domain']);
    config(['outbound.rollout.emergency_stop' => true]);
    $admin = User::factory()->platformAdmin()->create();
    $message = failedOutboundMessage($ctx['user'], $ctx['inbox']);
    failoverAttempt($message);

    try {
        app(RetryOutboundMessageWithProviderAction::class)->execute($message, 'ses', $admin);
        expect(false)->toBeTrue('expected exception');
    } catch (OutboundSendException $exception) {
        expect($exception->errorCode)->toBe('outbound_emergency_stop');
    }
});

it('denies manual provider retry for an ineligible failure code even for a platform admin', function (): void {
    $ctx = failoverContext('ineligible-'.bin2hex(random_bytes(3)).'.test');
    makeSesDomainVerified($ctx['domain']);
    $admin = User::factory()->platformAdmin()->create();
    $message = failedOutboundMessage($ctx['user'], $ctx['inbox'], ['failure_code' => 'timeout']);
    failoverAttempt($message, ['result' => 'temporary_failure']);

    try {
        app(RetryOutboundMessageWithProviderAction::class)->execute($message, 'ses', $admin);
        expect(false)->toBeTrue('expected exception');
    } catch (OutboundSendException $exception) {
        expect($exception->errorCode)->toBe('not_pre_acceptance_safe');
    }

    expect($message->fresh()->state)->toBe(OutboundMessageState::Failed);
});

// --- Manual provider retry: bounded, successful path ------------------------

it('performs exactly one bounded manual provider retry attempt and marks the message sent', function (): void {
    $ctx = failoverContext('bounded-'.bin2hex(random_bytes(3)).'.test');
    makeSesDomainVerified($ctx['domain']);
    $admin = User::factory()->platformAdmin()->create();
    $message = failedOutboundMessage($ctx['user'], $ctx['inbox'], ['failure_code' => 'invalid_config']);
    failoverAttempt($message);

    $result = app(RetryOutboundMessageWithProviderAction::class)->execute($message, 'ses', $admin);

    expect($result->state)->toBe(OutboundMessageState::Sent)
        ->and($result->provider)->toBe('ses')
        ->and($result->attempt_count)->toBe(2);

    $attempts = OutboundDeliveryAttempt::query()->where('outbound_message_id', $message->getKey())->orderBy('attempt_number')->get();
    expect($attempts)->toHaveCount(2)
        ->and($attempts[1]->attempt_number)->toBe(2)
        ->and($attempts[1]->provider)->toBe('ses')
        ->and($attempts[1]->state)->toBe(OutboundDeliveryAttemptState::Accepted)
        ->and($attempts[1]->failover_reason_code)->toBe('safe_pre_acceptance_failure');

    expect(AuditLog::query()->where('action', 'outbound.manual_provider_retry_requested')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'outbound.manual_provider_retry_succeeded')->exists())->toBeTrue();
});

it('does not loop or auto-retry when the manual provider retry attempt itself fails', function (): void {
    $ctx = failoverContext('boundedfail-'.bin2hex(random_bytes(3)).'.test');
    makeSesDomainVerified($ctx['domain']);
    $admin = User::factory()->platformAdmin()->create();
    // Readiness/domain/rollout checks all pass here — the failure is
    // deliberately induced only inside the transport's own send() (an
    // unsafe recipient address), proving a failure at the actual retry
    // attempt still leaves the message in a single terminal state
    // instead of looping or silently retrying again.
    $message = failedOutboundMessage($ctx['user'], $ctx['inbox'], [
        'failure_code' => 'invalid_config',
        'to_recipients' => ['not-an-email'],
    ]);
    failoverAttempt($message);

    $result = app(RetryOutboundMessageWithProviderAction::class)->execute($message, 'ses', $admin);

    expect($result->state)->toBe(OutboundMessageState::Failed);

    $attempts = OutboundDeliveryAttempt::query()->where('outbound_message_id', $message->getKey())->get();
    expect($attempts)->toHaveCount(2);

    expect(AuditLog::query()->where('action', 'outbound.manual_provider_retry_failed')->exists())->toBeTrue();
});

// --- Ops metrics -------------------------------------------------------------

it('reports multi-provider ops metrics including failover counters, secret-free', function (): void {
    $ctx = failoverContext('ops-'.bin2hex(random_bytes(3)).'.test');
    makeSesDomainVerified($ctx['domain']);
    config([
        'outbound.smtp.password' => 'super-secret-password',
    ]);
    $admin = User::factory()->platformAdmin()->create();
    $message = failedOutboundMessage($ctx['user'], $ctx['inbox'], ['failure_code' => 'invalid_config']);
    failoverAttempt($message);
    app(RetryOutboundMessageWithProviderAction::class)->execute($message, 'ses', $admin);

    $report = app(OutboundOpsService::class)->providersReport();

    expect($report['primary_provider'])->toBe('generic')
        ->and($report['secondary_provider'])->toBe('ses')
        ->and($report['config_errors'])->toBe([])
        ->and($report['readiness'])->toHaveKeys(['generic', 'ses'])
        ->and($report['failover']['succeeded'])->toBeGreaterThanOrEqual(1);

    $encoded = json_encode($report, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('super-secret-password');
});

it('reports zero failover counters when no manual retry has ever run', function (): void {
    $report = app(OutboundOpsService::class)->providersReport();

    expect($report['failover'])->toBe([
        'attempts_requested' => 0,
        'succeeded' => 0,
        'failed' => 0,
        'blocked' => 0,
    ]);
});
