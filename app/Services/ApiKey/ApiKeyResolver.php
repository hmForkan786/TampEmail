<?php

declare(strict_types=1);

namespace App\Services\ApiKey;

use App\Models\ApiKey;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use RuntimeException;

final class ApiKeyResolver
{
    private const TOKEN_PATTERN = '/^te_live_[A-Za-z0-9_-]{43}$/D';

    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeyRepository,
        private readonly ApiKeyTokenHasher $tokenHasher,
    ) {}

    public function resolve(string $plainToken): ?ApiKey
    {
        if (preg_match(self::TOKEN_PATTERN, $plainToken) !== 1) {
            return null;
        }

        $keyPrefix = substr($plainToken, 0, 16);
        try {
            $keyHash = $this->tokenHasher->hash($plainToken);
        } catch (RuntimeException) {
            return null;
        }
        $apiKey = $this->apiKeyRepository->findActiveByPrefixAndHash($keyPrefix, $keyHash);

        try {
            $verified = $apiKey !== null && $this->tokenHasher->verify($plainToken, $apiKey->key_hash);
        } catch (RuntimeException) {
            return null;
        }

        if (! $verified) {
            return null;
        }

        return $apiKey;
    }
}
