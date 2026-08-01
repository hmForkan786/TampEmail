<?php

declare(strict_types=1);

use App\DTOs\Billing\RawWebhookRequest;
use App\Enums\SignatureEncoding;
use App\Enums\WebhookCanonicalizationStrategy;
use App\Services\Billing\Webhook\HmacSignatureVerifier;
use App\Services\Billing\Webhook\WebhookPayloadCanonicalizer;
use App\Services\Billing\Webhook\WebhookTimestampValidator;

it('verifies strict deterministic HMAC vectors', function (): void {
    $service = new HmacSignatureVerifier;
    $payload = '1700000000.fixture-nonce-00000001.{"event_id":"evt_1"}';
    $secret = 'obvious-test-only-secret';
    $hex = hash_hmac('sha256', $payload, $secret);
    $base64 = base64_encode(hash_hmac('sha256', $payload, $secret, true));

    expect($service->verify('sha256', $secret, $payload, $hex, SignatureEncoding::Hex))->toBeTrue()
        ->and($service->verify('sha256', $secret, $payload, $base64, SignatureEncoding::Base64))->toBeTrue()
        ->and($service->verify('sha512', $secret, $payload, hash_hmac('sha512', $payload, $secret), SignatureEncoding::Hex))->toBeTrue()
        ->and($service->verify('sha256', $secret, $payload.' ', $hex, SignatureEncoding::Hex))->toBeFalse()
        ->and($service->verify('sha256', $secret, $payload, 'abc', SignatureEncoding::Hex))->toBeFalse();
    expect(fn () => $service->verify('sha1', $secret, $payload, $hex, SignatureEncoding::Hex))->toThrow(InvalidArgumentException::class);
});

it('preserves exact raw bytes during canonicalization', function (): void {
    $body = "{ \"unicode\": \"বাংলা\", \"a\": 1 }\n";
    $request = new RawWebhookRequest('fake', 'POST', '/callback', 'b=2&a=1', $body, [], 'application/json', strlen($body), '127.0.0.1', new DateTimeImmutable('@1700000000'), 'request-1');
    $result = (new WebhookPayloadCanonicalizer)->canonicalize($request, WebhookCanonicalizationStrategy::TimestampNonceRawBody, '1700000000', 'fixture-nonce-00000001');

    expect($result->bytes)->toBe('1700000000.fixture-nonce-00000001.'.$body)
        ->and($result->hash)->toBe(hash('sha256', $result->bytes));
});

it('validates seconds milliseconds old and future timestamps deterministically', function (): void {
    $validator = new WebhookTimestampValidator;
    $now = new DateTimeImmutable('@1700000000');

    expect($validator->validate('1700000000', 300, 60, $now)['valid'])->toBeTrue()
        ->and($validator->validate('1700000000000', 300, 60, $now)['valid'])->toBeTrue()
        ->and($validator->validate('1699999600', 300, 60, $now)['code'])->toBe('too_old')
        ->and($validator->validate('1700000061', 300, 60, $now)['code'])->toBe('too_far_in_future')
        ->and($validator->validate('not-a-date', 300, 60, $now)['code'])->toBe('malformed');
});
