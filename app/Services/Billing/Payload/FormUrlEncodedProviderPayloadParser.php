<?php

declare(strict_types=1);

namespace App\Services\Billing\Payload;

use App\Contracts\Billing\ProviderPayloadParser;
use App\Exceptions\Billing\PaymentVerificationException;

final class FormUrlEncodedProviderPayloadParser implements ProviderPayloadParser
{
    public function provider(): string
    {
        return 'sslcommerz';
    }

    public function supports(string $contentType): bool
    {
        return $contentType === 'application/x-www-form-urlencoded';
    }

    /** @return array<string, string> */
    public function parse(string $rawBody, string $contentType): array
    {
        if ($rawBody === '') {
            throw new PaymentVerificationException('Empty provider payload.');
        }
        $parts = explode('&', $rawBody);
        if (count($parts) > $this->limit('billing.callbacks.max_fields', 100)) {
            throw new PaymentVerificationException('Provider payload contains too many fields.');
        }
        $result = [];
        foreach ($parts as $part) {
            if ($part === '' || ! str_contains($part, '=')) {
                throw new PaymentVerificationException('Malformed form payload.');
            }
            [$rawKey, $rawValue] = explode('=', $part, 2);
            if (preg_match('/%(?![0-9A-Fa-f]{2})/', $rawKey.$rawValue) === 1) {
                throw new PaymentVerificationException('Malformed percent encoding.');
            }
            $key = urldecode($rawKey);
            $value = urldecode($rawValue);
            if ($key === '' || str_contains($key, '[') || str_contains($key, ']') || array_key_exists($key, $result)) {
                throw new PaymentVerificationException('Ambiguous form payload.');
            }
            if (strlen($key) > 100 || strlen($value) > $this->limit('billing.callbacks.max_field_bytes', 4096)) {
                throw new PaymentVerificationException('Provider payload field exceeds limits.');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private function limit(string $key, int $default): int
    {
        return app()->bound('config') ? (int) config($key, $default) : $default;
    }
}
