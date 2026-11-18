<?php

declare(strict_types=1);

namespace App\Services\Affiliates;

use Illuminate\Support\Str;

/**
 * Deterministic HMAC hashing for affiliate PII (IPs, user agents, visitor tokens).
 *
 * Raw values are never persisted; only these hashes are stored so identifiers
 * can be compared without retaining personally identifiable information.
 */
final class AffiliateHashingService
{
    public function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('affiliates.hash_key'));
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

    public function hashVisitorToken(string $token): string
    {
        return $this->hash($token);
    }
}
