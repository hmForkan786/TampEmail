<?php

declare(strict_types=1);

namespace App\Services\ApiKey;

use App\Enums\ApiKeyScope;
use App\Enums\PlatformRole;
use App\Exceptions\InvalidApiKeyScopeException;

/**
 * Canonical API-key scope allowlist and normalization helpers.
 *
 * Pool entitlements such as `mail_server_pools` are not API-key scopes and are
 * rejected by {@see normalize()}.
 *
 * Legacy `api_keys.permissions` values are not rewritten here. Authentication
 * middleware continues to trust stored strings until a later issuance/runtime
 * enforcement prompt wires this registry into create/update paths.
 */
final class ApiKeyScopeRegistry
{
    public static function isKnown(string $scope): bool
    {
        return ApiKeyScope::tryFrom($scope) !== null;
    }

    /**
     * Normalize a raw permissions payload to a unique, stably ordered scope list.
     *
     * @param  array<mixed>  $scopes
     * @return list<string>
     *
     * @throws InvalidApiKeyScopeException
     */
    public static function normalize(array $scopes): array
    {
        /** @var array<string, ApiKeyScope> $unique */
        $unique = [];

        foreach ($scopes as $scope) {
            if (! is_string($scope)) {
                throw new InvalidApiKeyScopeException('API key scopes must be strings.');
            }

            if ($scope === '' || trim($scope) === '') {
                throw new InvalidApiKeyScopeException('API key scopes must not be blank.');
            }

            // Exact match only: leading/trailing whitespace must not become a privilege.
            $known = ApiKeyScope::tryFrom($scope);

            if ($known === null) {
                throw new InvalidApiKeyScopeException("Unknown API key scope [{$scope}].");
            }

            $unique[$known->value] = $known;
        }

        $normalized = [];

        foreach (ApiKeyScope::cases() as $case) {
            if (isset($unique[$case->value])) {
                $normalized[] = $case->value;
            }
        }

        return $normalized;
    }

    /**
     * Resolve the minimum owner platform role required for a known scope.
     */
    public static function requiredCapability(ApiKeyScope $scope): PlatformRole
    {
        return $scope->requiredCapability();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (ApiKeyScope $scope): string => $scope->value,
            ApiKeyScope::cases(),
        );
    }
}
