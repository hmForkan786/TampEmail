<?php

declare(strict_types=1);

namespace App\Services\ApiKey;

use RuntimeException;

final class ApiKeyTokenHasher
{
    public function hash(string $plainToken): string
    {
        return 'v1:'.hash_hmac('sha256', $plainToken, $this->secret());
    }

    public function verify(string $plainToken, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($plainToken));
    }

    private function secret(): string
    {
        $secret = config('api.key_hash_secret');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('API_KEY_HASH_SECRET must be configured.');
        }

        return $secret;
    }
}
