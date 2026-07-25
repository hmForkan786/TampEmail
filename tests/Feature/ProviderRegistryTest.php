<?php

declare(strict_types=1);

use App\Contracts\OutboundTransportInterface;
use App\Services\Outbound\GenericOutboundProviderEventParser;
use App\Services\Outbound\OutboundProviderRegistry;
use App\Services\Outbound\SesOutboundProviderEventParser;
use App\Services\Outbound\UnavailableOutboundTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'outbound.enabled' => true,
        'outbound.transport' => 'array',
        'outbound.primary_provider' => 'generic',
        'outbound.provider' => 'generic',
        'outbound.secondary_provider' => null,
        'outbound.failover_enabled' => false,
        'outbound.delivery_webhook.providers.generic.secret' => 'test-secret',
        'outbound.delivery_webhook.providers.ses.topic_arn' => null,
    ]);
});

it('exposes the fixed, supported provider list', function (): void {
    $registry = app(OutboundProviderRegistry::class);

    expect($registry->supportedProviders())->toBe(['generic', 'ses'])
        ->and($registry->isSupported('generic'))->toBeTrue()
        ->and($registry->isSupported('ses'))->toBeTrue()
        ->and($registry->isSupported('sendgrid'))->toBeFalse()
        ->and($registry->isSupported(''))->toBeFalse();
});

it('fails closed for unsupported providers everywhere', function (): void {
    $registry = app(OutboundProviderRegistry::class);

    expect(fn () => $registry->assertSupported('sendgrid'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->resolveTransport('sendgrid'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->resolveParser('sendgrid'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->capabilities('sendgrid'))->toThrow(InvalidArgumentException::class);

    // readiness() never throws — it reports unsupported as not-ready instead,
    // since it is read by ops surfaces that must never 500 on bad input.
    $readiness = $registry->readiness('sendgrid');
    expect($readiness['supported'])->toBeFalse()
        ->and($readiness['ready'])->toBeFalse()
        ->and($readiness['failure_code'])->toBe('unsupported_provider');
});

it('resolves a correctly tagged transport and the matching parser per provider', function (): void {
    $registry = app(OutboundProviderRegistry::class);

    $genericTransport = $registry->resolveTransport('generic');
    expect($genericTransport)->toBeInstanceOf(OutboundTransportInterface::class);

    $sesTransport = $registry->resolveTransport('ses');
    expect($sesTransport)->toBeInstanceOf(OutboundTransportInterface::class);

    expect($registry->resolveParser('generic'))->toBeInstanceOf(GenericOutboundProviderEventParser::class)
        ->and($registry->resolveParser('ses'))->toBeInstanceOf(SesOutboundProviderEventParser::class);
});

it('falls back to an unavailable transport when the transport driver is misconfigured', function (): void {
    config(['outbound.transport' => 'unavailable']);
    $registry = app(OutboundProviderRegistry::class);

    expect($registry->resolveTransport('generic'))->toBeInstanceOf(UnavailableOutboundTransport::class);
});

it('resolves primary and secondary provider configuration correctly', function (): void {
    config([
        'outbound.primary_provider' => 'generic',
        'outbound.secondary_provider' => 'ses',
    ]);
    $registry = app(OutboundProviderRegistry::class);

    expect($registry->primaryProvider())->toBe('generic')
        ->and($registry->secondaryProvider())->toBe('ses');
});

it('keeps outbound.provider and outbound.primary_provider in agreement for back-compat call sites', function (): void {
    // config/outbound.php derives both `provider` and `primary_provider`
    // from the same OUTBOUND_PRIMARY_PROVIDER/OUTBOUND_PROVIDER env pair, so
    // any pre-existing call site reading config('outbound.provider')
    // directly always agrees with the registry's primaryProvider().
    config(['outbound.provider' => 'ses', 'outbound.primary_provider' => 'ses']);

    expect(app(OutboundProviderRegistry::class)->primaryProvider())->toBe('ses')
        ->and((string) config('outbound.provider'))->toBe('ses');
});

it('treats an unsupported or duplicate secondary provider as no secondary at all', function (): void {
    config(['outbound.primary_provider' => 'generic', 'outbound.secondary_provider' => 'sendgrid']);
    expect(app(OutboundProviderRegistry::class)->secondaryProvider())->toBeNull();

    config(['outbound.primary_provider' => 'generic', 'outbound.secondary_provider' => 'generic']);
    expect(app(OutboundProviderRegistry::class)->secondaryProvider())->toBeNull();

    config(['outbound.primary_provider' => 'generic', 'outbound.secondary_provider' => '']);
    expect(app(OutboundProviderRegistry::class)->secondaryProvider())->toBeNull();
});

it('reports failover disabled by default even with a usable secondary configured', function (): void {
    config(['outbound.primary_provider' => 'generic', 'outbound.secondary_provider' => 'ses']);
    expect(app(OutboundProviderRegistry::class)->failoverEnabled())->toBeFalse();

    config(['outbound.failover_enabled' => true]);
    expect(app(OutboundProviderRegistry::class)->failoverEnabled())->toBeTrue();

    // ...but never true without a usable secondary, even if the flag is set.
    config(['outbound.secondary_provider' => null]);
    expect(app(OutboundProviderRegistry::class)->failoverEnabled())->toBeFalse();
});

it('reports secret-free configuration errors for invalid primary/secondary combinations', function (): void {
    config(['outbound.primary_provider' => '', 'outbound.enabled' => true]);
    expect(app(OutboundProviderRegistry::class)->configErrors())->toContain('primary_provider_required');

    config(['outbound.primary_provider' => 'sendgrid', 'outbound.enabled' => true]);
    expect(app(OutboundProviderRegistry::class)->configErrors())->toContain('primary_provider_unsupported');

    config(['outbound.primary_provider' => 'generic', 'outbound.secondary_provider' => 'sendgrid']);
    expect(app(OutboundProviderRegistry::class)->configErrors())->toContain('secondary_provider_unsupported');

    config(['outbound.primary_provider' => 'generic', 'outbound.secondary_provider' => 'generic']);
    expect(app(OutboundProviderRegistry::class)->configErrors())->toContain('secondary_provider_duplicates_primary');

    config(['outbound.primary_provider' => 'generic', 'outbound.secondary_provider' => null, 'outbound.failover_enabled' => true]);
    expect(app(OutboundProviderRegistry::class)->configErrors())->toContain('failover_enabled_without_usable_secondary');

    config(['outbound.primary_provider' => 'generic', 'outbound.secondary_provider' => 'ses', 'outbound.failover_enabled' => true]);
    expect(app(OutboundProviderRegistry::class)->configErrors())->toBe([]);
});

it('reports static capabilities without ever probing a live connection', function (): void {
    $registry = app(OutboundProviderRegistry::class);

    $generic = $registry->capabilities('generic');
    expect($generic['provider'])->toBe('generic')
        ->and($generic['smtp_submission'])->toBeTrue()
        ->and($generic['delivery_webhooks'])->toBeTrue()
        ->and($generic['safe_connectivity_probe'])->toBeFalse()
        ->and($generic['provider_message_id'])->toBeTrue();

    $ses = $registry->capabilities('ses');
    expect($ses['provider'])->toBe('ses')
        ->and($ses['bounce_events'])->toBeTrue()
        ->and($ses['complaint_events'])->toBeTrue();
});

it('reports readiness as secret-free and correctly reflects webhook/transport state', function (): void {
    config([
        'outbound.smtp.host' => 'smtp.example.test',
        'outbound.smtp.port' => 587,
        'outbound.smtp.username' => 'user',
        'outbound.smtp.password' => 'super-secret-password',
        'outbound.smtp.encryption' => 'tls',
    ]);
    $registry = app(OutboundProviderRegistry::class);

    $readiness = $registry->readiness('generic');
    expect($readiness['ready'])->toBeTrue()
        ->and($readiness['is_primary'])->toBeTrue()
        ->and($readiness['is_secondary'])->toBeFalse();

    $encoded = json_encode($readiness, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('super-secret-password')
        ->and($encoded)->not->toContain('user');

    // SES is not ready without a topic ARN configured.
    $sesReadiness = $registry->readiness('ses');
    expect($sesReadiness['ready'])->toBeFalse()
        ->and($sesReadiness['webhook_secret_present'])->toBeFalse();

    config(['outbound.delivery_webhook.providers.ses.topic_arn' => 'arn:aws:sns:us-east-1:123456789012:topic']);
    $sesReadyNow = app(OutboundProviderRegistry::class)->readiness('ses');
    expect($sesReadyNow['ready'])->toBeTrue();
});
