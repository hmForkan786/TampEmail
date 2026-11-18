<?php

declare(strict_types=1);

use App\Models\PaymentProviderEvent;
use App\Models\WebhookReplayNonce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config()->set('billing.webhook_security.environment', 'testing');
    config()->set('billing.webhook_security.providers.fake.enabled', true);
    config()->set('billing.webhook_security.providers.fake.secret', 'obvious-test-only-webhook-secret');
});

it('enforces webhook verification before creating a processable event and stores only a nonce hash', function (): void {
    $payload = ['event_id' => 'secure-event-1'];
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->withHeaders(['X-Fake-Signature' => 'v1='.str_repeat('0', 64), 'X-Fake-Timestamp' => (string) now()->timestamp, 'X-Fake-Nonce' => 'security-nonce-00000001'])
        ->postJson('/api/v1/billing/providers/fake/callback', $payload)->assertUnauthorized()
        ->assertExactJson(['accepted' => false, 'code' => 'invalid_webhook_signature']);
    expect(PaymentProviderEvent::query()->count())->toBe(0)->and(WebhookReplayNonce::query()->count())->toBe(0);

    $headers = signedFakeCallbackHeaders($raw, 'security-nonce-00000001');
    $this->call('POST', '/api/v1/billing/providers/fake/callback', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => $headers['X-Fake-Signature'],
        'HTTP_X_FAKE_TIMESTAMP' => $headers['X-Fake-Timestamp'], 'HTTP_X_FAKE_NONCE' => $headers['X-Fake-Nonce'],
    ], $raw)->assertAccepted();
    expect(WebhookReplayNonce::query()->first()->nonce_hash)->not->toContain('security-nonce');
});

it('allows an exact signed retry but rejects a nonce reused with changed bytes', function (): void {
    $raw = '{"event_id":"secure-retry","payment_status":"pending"}';
    $headers = signedFakeCallbackHeaders($raw, 'security-nonce-00000002');
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => $headers['X-Fake-Signature'], 'HTTP_X_FAKE_TIMESTAMP' => $headers['X-Fake-Timestamp'], 'HTTP_X_FAKE_NONCE' => $headers['X-Fake-Nonce']];
    $this->call('POST', '/api/v1/billing/providers/fake/callback', [], [], [], $server, $raw)->assertAccepted();
    $this->call('POST', '/api/v1/billing/providers/fake/callback', [], [], [], $server, $raw)->assertAccepted();

    $changed = '{"event_id":"secure-retry", "payment_status":"pending"}';
    $changedHeaders = signedFakeCallbackHeaders($changed, 'security-nonce-00000002', $headers['X-Fake-Timestamp']);
    $server['HTTP_X_FAKE_SIGNATURE'] = $changedHeaders['X-Fake-Signature'];
    $this->call('POST', '/api/v1/billing/providers/fake/callback', [], [], [], $server, $changed)->assertUnauthorized();
    expect(PaymentProviderEvent::query()->count())->toBe(1);
});

it('rejects disabled and unconfigured provider adapters', function (): void {
    config()->set('billing.webhook_security.providers.fake.enabled', false);
    $this->postJson('/api/v1/billing/providers/fake/callback', [])->assertUnauthorized();
    config()->set('billing.webhook_security.providers.stripe.enabled', true);
    $this->postJson('/api/v1/billing/providers/stripe/callback', [])->assertUnauthorized();
});
