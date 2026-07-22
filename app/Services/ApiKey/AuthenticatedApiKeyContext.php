<?php

declare(strict_types=1);

namespace App\Services\ApiKey;

use App\Models\ApiKey;

final readonly class AuthenticatedApiKeyContext
{
    public function __construct(public ApiKey $apiKey) {}

    public function id(): string
    {
        return $this->apiKey->id;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return $this->apiKey->permissions ?? [];
    }

    public function allows(string $scope): bool
    {
        return $this->apiKey->hasPermission($scope)
            || ($this->apiKey->hasPermission('mail_servers:admin')
                && str_starts_with($scope, 'mail_servers:'));
    }
}
