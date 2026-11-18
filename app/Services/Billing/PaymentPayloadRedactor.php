<?php

declare(strict_types=1);

namespace App\Services\Billing;

final class PaymentPayloadRedactor
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'pan', 'card_number', 'cardnumber', 'cvv', 'cvc', 'pin', 'otp',
        'password', 'secret', 'token', 'access_token', 'refresh_token',
        'authorization', 'api_key', 'private_key',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        $redacted = [];
        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $redacted[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redact($value);

                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($this->redact($payload), JSON_THROW_ON_ERROR));
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
