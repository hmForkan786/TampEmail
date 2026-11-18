<?php

declare(strict_types=1);

use App\Models\ProviderSigningKey;
use App\Models\WebhookReplayNonce;
use App\Services\Billing\Webhook\ProviderSigningKeyResolver;
use App\Services\Billing\Webhook\ProviderWebhookVerifierRegistry;
use App\Services\Billing\Webhook\UnconfiguredProviderWebhookVerifier;
use App\Services\Billing\Webhook\WebhookReplayProtectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves only valid environment-scoped active and retiring encrypted keys', function (): void {
    foreach ([
        ['active-key', 'active', 'testing', null],
        ['retiring-key', 'retiring', 'testing', now()->addMinute()],
        ['revoked-key', 'revoked', 'testing', null],
        ['expired-key', 'retiring', 'testing', now()->subMinute()],
        ['production-key', 'active', 'production', null],
    ] as [$id, $status, $environment, $until]) {
        ProviderSigningKey::query()->create([
            'provider' => 'fake', 'key_id' => $id, 'algorithm' => 'sha256',
            'secret_encrypted' => 'fixture-secret-'.$id, 'status' => $status,
            'environment' => $environment, 'valid_from' => now()->subMinute(), 'valid_until' => $until,
        ]);
    }
    config()->set('billing.webhook_security.providers.fake.secret', '');
    $keys = app(ProviderSigningKeyResolver::class)->resolve('fake', 'testing');

    expect(array_column($keys, 'id'))->toEqualCanonicalizing(['active-key', 'retiring-key'])
        ->and(json_encode(ProviderSigningKey::query()->first(), JSON_THROW_ON_ERROR))->not->toContain('fixture-secret');
});

it('classifies provider-scoped exact retries and conflicting replays without storing raw nonce', function (): void {
    $service = app(WebhookReplayProtectionService::class);
    $nonce = 'high-entropy-nonce-00000001';

    expect($service->reserve('fake', $nonce, str_repeat('a', 64), 'key-1', '127.0.0.1', 300))->toBe('first_seen')
        ->and($service->reserve('fake', $nonce, str_repeat('a', 64), 'key-1', '127.0.0.1', 300))->toBe('exact_retry')
        ->and($service->reserve('fake', $nonce, str_repeat('b', 64), 'key-1', '127.0.0.1', 300))->toBe('conflicting_replay')
        ->and($service->reserve('another', $nonce, str_repeat('b', 64), 'key-1', null, 300))->toBe('first_seen')
        ->and(WebhookReplayNonce::query()->where('nonce_hash', $nonce)->exists())->toBeFalse();
});

it('rejects duplicate verifier registration and real-provider stubs fail closed', function (): void {
    $stub = new UnconfiguredProviderWebhookVerifier('stripe');
    expect(fn () => new ProviderWebhookVerifierRegistry([$stub, $stub]))->toThrow(LogicException::class)
        ->and($stub->supportsSignatureVersion('v1'))->toBeFalse();
});

it('prunes expired nonce records with dry-run support', function (): void {
    WebhookReplayNonce::query()->create([
        'provider' => 'fake', 'nonce_hash' => str_repeat('a', 64), 'request_fingerprint' => str_repeat('b', 64),
        'first_seen_at' => now()->subHour(), 'expires_at' => now()->subMinute(),
    ]);
    $this->artisan('billing:prune-webhook-security --dry-run')->assertSuccessful()->expectsOutputToContain('Would prune 1');
    expect(WebhookReplayNonce::query()->count())->toBe(1);
    $this->artisan('billing:prune-webhook-security')->assertSuccessful()->expectsOutputToContain('Pruned 1');
    expect(WebhookReplayNonce::query()->count())->toBe(0);
});
