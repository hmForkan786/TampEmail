<?php

declare(strict_types=1);

namespace App\Services\ApiKey;

use App\Enums\ApiKeyScope;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Exceptions\ApiKeyScopeNotAllowedException;
use App\Exceptions\InvalidApiKeyScopeException;
use App\Models\User;

/**
 * Canonical API-key scope allowlist, normalization, and owner authorization.
 *
 * Pool entitlements such as `mail_server_pools` are not API-key scopes and are
 * rejected by {@see normalize()}.
 *
 * Legacy `api_keys.permissions` values are not rewritten here. Authentication
 * middleware continues to trust stored strings for existing keys; create/update
 * paths must call {@see authorizeForOwner()} before persistence.
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
     * Whether the locked owner may hold the given known scope.
     *
     * Active lifecycle is required for every scope. Operator/admin checks use
     * the platform-role helpers (admins satisfy operator-minimum scopes).
     */
    public static function ownerAllows(User $owner, ApiKeyScope $scope): bool
    {
        if ($owner->trashed() || $owner->status !== UserStatus::Active) {
            return false;
        }

        return match ($scope->requiredCapability()) {
            PlatformRole::User => true,
            PlatformRole::Operator => $owner->isPlatformOperator(),
            PlatformRole::Admin => $owner->isPlatformAdmin(),
        };
    }

    /**
     * Normalize permissions (when present) and assert the owner may hold them.
     *
     * @param  list<mixed>|null  $permissions
     * @return list<string>|null Normalized scopes, or null when no scopes were supplied.
     *
     * @throws InvalidApiKeyScopeException
     * @throws ApiKeyScopeNotAllowedException
     */
    public static function authorizeForOwner(User $owner, ?array $permissions): ?array
    {
        if ($permissions === null) {
            return null;
        }

        $normalized = self::normalize($permissions);

        foreach ($normalized as $scopeValue) {
            $scope = ApiKeyScope::from($scopeValue);

            if (! self::ownerAllows($owner, $scope)) {
                throw new ApiKeyScopeNotAllowedException(
                    "The API key owner is not allowed to hold the [{$scopeValue}] scope."
                );
            }
        }

        return $normalized;
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
