<?php

use App\Exceptions\OutboundSendException;
use App\Models\WebhookDelivery;
use App\Services\Webhook\WebhookSecurityValidator;
use App\Services\Webhook\WebhookSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['api.key_hash_secret' => 'webhook-security-secret']));

it('rejects non-https and unsafe webhook targets at registration', function (): void {
    ['token' => $token] = premiumWebhookFixture();
    $validator = app(WebhookSecurityValidator::class);

    $this->withToken($token)->postJson('/api/v1/webhooks', webhookPayload(['url' => 'http://example.com/hook']))->assertStatus(422);
    expect(fn () => $validator->assertSafeUrl('https://127.0.0.1/hook'))->toThrow(OutboundSendException::class);
    expect(fn () => $validator->assertSafeUrl('https://10.0.0.1/hook'))->toThrow(OutboundSendException::class);
    expect(fn () => $validator->assertSafeUrl('https://[::1]/hook'))->toThrow(OutboundSendException::class);
    expect(fn () => $validator->assertSafeUrl('https://169.254.169.254/latest/meta-data'))->toThrow(OutboundSendException::class);
});

it('verifies webhook signatures and rejects tampered bodies', function (): void {
    $signer = app(WebhookSignatureService::class);
    $secret = 'signing-secret';
    $body = '{"event":"ok"}';
    $timestamp = 1_700_000_000;
    $signature = $signer->sign($secret, $timestamp, $body);

    expect($signer->verify($secret, $timestamp, $body, $signature))->toBeTrue()
        ->and($signer->verify($secret, $timestamp, '{"event":"bad"}', $signature))->toBeFalse();
});

it('uses deterministic timestamp headers under a fixed clock', function (): void {
    $delivery = new WebhookDelivery([
        'event_id' => 'evt-1',
        'event_type' => 'outbound.message.sent',
        'attempt_count' => 0,
    ]);
    $delivery->id = (string) Str::uuid();

    Carbon::setTestNow('2026-07-27 12:00:00');
    $headers = app(WebhookSignatureService::class)->headers($delivery, 'secret', '{}', 1_700_000_000);
    expect($headers['X-Webhook-Timestamp'])->toBe('1700000000')
        ->and($headers['X-Webhook-Id'])->toBe('evt-1')
        ->and($headers['X-Webhook-Attempt'])->toBe('1');
    Carbon::setTestNow();
});

it('accepts publicly routable https targets', function (): void {
    app(WebhookSecurityValidator::class)->assertSafeUrl('https://example.com/webhooks/temail');
    expect(true)->toBeTrue();
});
