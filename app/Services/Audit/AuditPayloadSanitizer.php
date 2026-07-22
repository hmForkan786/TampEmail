<?php

declare(strict_types=1);

namespace App\Services\Audit;

use DateTimeInterface;

final class AuditPayloadSanitizer
{
    private const MAX_DEPTH = 8;
    private const MAX_STRING_LENGTH = 4096;

    /** @var array<string, true> */
    private const DENY_KEYS = [
        'password' => true, 'plaintexttoken' => true, 'token' => true, 'secret' => true,
        'keyhash' => true, 'hash' => true, 'credential' => true, 'privatekey' => true,
        'authorization' => true, 'requestbody' => true, 'responsebody' => true,
        'headers' => true, 'cookies' => true, 'rawpayload' => true,
    ];

    /** @param array<string, mixed>|null $payload */
    public function sanitize(?array $payload): ?array
    {
        return $payload === null ? null : $this->sanitizeArray($payload, 0);
    }

    /** @param array<mixed> $value */
    private function sanitizeArray(array $value, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) return [];
        $result = [];
        foreach ($value as $key => $item) {
            if ($this->isDeniedKey((string) $key)) continue;
            $sanitized = $this->sanitizeValue($item, $depth + 1);
            if ($sanitized !== null || $item === null) $result[$key] = $sanitized;
        }
        return $result;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($depth >= self::MAX_DEPTH) return null;
        if ($value instanceof DateTimeInterface) return $value->format(DATE_ATOM);
        if (is_string($value)) return mb_substr($value, 0, self::MAX_STRING_LENGTH);
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) return $value;
        if (is_array($value)) return $this->sanitizeArray($value, $depth);
        return null;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
    }

    private function isDeniedKey(string $key): bool
    {
        $normalized = $this->normalizeKey($key);
        return isset(self::DENY_KEYS[$normalized])
            || str_contains($normalized, 'token')
            || str_contains($normalized, 'authorization');
    }
}
