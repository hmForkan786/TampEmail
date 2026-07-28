<?php

declare(strict_types=1);

use App\Exceptions\Billing\PaymentVerificationException;
use App\Services\Billing\Payload\FormUrlEncodedProviderPayloadParser;
use App\Services\Billing\Payload\JsonProviderPayloadParser;

it('parses flat SSLCommerz form payloads without coercing identifiers', function (): void {
    $parser = new FormUrlEncodedProviderPayloadParser;
    $raw = 'tran_id=000123&status=VALID&value_a=order-1&name=Bangla+SaaS';

    expect($parser->parse($raw, 'application/x-www-form-urlencoded'))->toBe([
        'tran_id' => '000123', 'status' => 'VALID', 'value_a' => 'order-1', 'name' => 'Bangla SaaS',
    ]);
});

it('rejects duplicate nested malformed and overlong form data', function (string $raw): void {
    expect(fn () => (new FormUrlEncodedProviderPayloadParser)->parse($raw, 'application/x-www-form-urlencoded'))
        ->toThrow(PaymentVerificationException::class);
})->with(['a=1&a=2', 'a%ZZ=1', 'a[b]=1', 'a='.str_repeat('x', 5000)]);

it('keeps JSON callbacks supported through the generic parser', function (): void {
    expect((new JsonProviderPayloadParser)->parse('{"event_id":"0001","amount":10}', 'application/json'))
        ->toBe(['event_id' => '0001', 'amount' => '10']);
});
