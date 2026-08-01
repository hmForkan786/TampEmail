<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Illuminate\Support\Str;

/**
 * Deterministic HMAC hashing for identity PII (emails, IPs, user agents, session ids).
 */
final class IdentityHashingService
{
    public function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('identity.hash_key'));
    }

    public function hashEmail(string $email): string
    {
        return $this->hash(strtolower(trim($email)));
    }

    public function hashIp(?string $ip): ?string
    {
        $ip = trim((string) $ip);

        return $ip === '' ? null : $this->hash($ip);
    }

    public function hashUserAgent(?string $userAgent): ?string
    {
        $userAgent = trim((string) $userAgent);

        if ($userAgent === '') {
            return null;
        }

        return $this->hash(Str::limit($userAgent, 500, ''));
    }

    public function maskSessionId(string $sessionId): string
    {
        if (strlen($sessionId) <= 8) {
            return str_repeat('*', strlen($sessionId));
        }

        return substr($sessionId, 0, 4).str_repeat('*', max(4, strlen($sessionId) - 8)).substr($sessionId, -4);
    }
}
