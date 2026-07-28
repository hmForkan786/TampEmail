<?php

declare(strict_types=1);

namespace App\Services\Billing\Payload;

use App\Contracts\Billing\ProviderPayloadParser;
use App\Exceptions\Billing\PaymentVerificationException;

final class JsonProviderPayloadParser implements ProviderPayloadParser
{
    public function provider(): string
    {
        return '*';
    }

    public function supports(string $contentType): bool
    {
        return $contentType === 'application/json';
    }

    public function parse(string $rawBody, string $contentType): array
    {
        $decoded = json_decode($rawBody, true);
        if (! is_array($decoded) || $decoded === [] || count($decoded) > $this->limit('billing.callbacks.max_fields', 100)) {
            throw new PaymentVerificationException('Invalid provider payload.');
        }
        $result = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key) || (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value) && $value !== null)) {
                throw new PaymentVerificationException('Invalid provider payload shape.');
            }
            $string = $value === null ? '' : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            if (strlen($key) > 100 || strlen($string) > $this->limit('billing.callbacks.max_field_bytes', 4096)) {
                throw new PaymentVerificationException('Provider payload field exceeds limits.');
            }
            $result[$key] = $string;
        }

        return $result;
    }

    private function limit(string $key, int $default): int
    {
        return app()->bound('config') ? (int) config($key, $default) : $default;
    }
}
