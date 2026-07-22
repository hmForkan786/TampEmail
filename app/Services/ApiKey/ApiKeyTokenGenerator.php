<?php

declare(strict_types=1);

namespace App\Services\ApiKey;

use Illuminate\Support\Str;

final class ApiKeyTokenGenerator
{
    public function __construct(private readonly ApiKeyTokenHasher $hasher) {}

    /** @return array{plain_token: string, key_prefix: string, key_hash: string} */
    public function generate(): array
    {
        $plainToken = 'te_live_'.Str::random(43);

        return [
            'plain_token' => $plainToken,
            'key_prefix' => substr($plainToken, 0, 16),
            'key_hash' => $this->hasher->hash($plainToken),
        ];
    }
}
