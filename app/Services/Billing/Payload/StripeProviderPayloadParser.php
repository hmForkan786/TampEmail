<?php

declare(strict_types=1);

namespace App\Services\Billing\Payload;

use App\Contracts\Billing\ProviderPayloadParser;
use App\Exceptions\Billing\PaymentVerificationException;

final class StripeProviderPayloadParser implements ProviderPayloadParser
{
    public function provider(): string
    {
        return 'stripe';
    }

    public function supports(string $contentType): bool
    {
        return $contentType === 'application/json';
    }

    /** @return array<string, mixed> */
    public function parse(string $rawBody, string $contentType): array
    {
        $decoded = json_decode($rawBody, true, 16);
        if (! is_array($decoded) || $decoded === []) {
            throw new PaymentVerificationException('Invalid Stripe payload.');
        }
        $fields = 0;
        $walk = function (mixed $value, int $depth) use (&$walk, &$fields): void {
            if ($depth > 12 || ++$fields > (int) config('billing.stripe.webhooks.max_fields', 500)) {
                throw new PaymentVerificationException('Stripe payload exceeds structural limits.');
            }
            if (is_array($value)) {
                foreach ($value as $key => $child) {
                    if ((is_string($key) && strlen($key) > 100) || is_object($child)) {
                        throw new PaymentVerificationException('Invalid Stripe payload shape.');
                    }
                    $walk($child, $depth + 1);
                }
            } elseif (is_string($value) && strlen($value) > (int) config('billing.callbacks.max_field_bytes', 4096)) {
                throw new PaymentVerificationException('Stripe payload field exceeds limits.');
            }
        };
        $walk($decoded, 0);

        return $decoded;
    }
}
