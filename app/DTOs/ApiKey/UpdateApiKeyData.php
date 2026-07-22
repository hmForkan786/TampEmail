<?php

declare(strict_types=1);

namespace App\DTOs\ApiKey;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Immutable input data for partially updating an API key record.
 */
final readonly class UpdateApiKeyData
{
    /**
     * @param string|null $userId Owning user UUID.
     * @param string|null $name Human-readable API key label.
     * @param string|null $keyPrefix Short public prefix for key identification.
     * @param string|null $keyHash Hashed secret for the API key.
     * @param list<string>|null $permissions Optional granted permission scopes.
     * @param int|null $rateLimitPerMinute Maximum requests allowed per minute.
     * @param CarbonInterface|null $expiresAt Optional expiration timestamp.
     * @param CarbonInterface|null $revokedAt Optional revocation timestamp.
     * @param array<string, mixed>|null $metadata Optional additional API key metadata.
     */
    public function __construct(
        public ?string $userId,
        public ?string $name,
        public ?string $keyPrefix,
        public ?string $keyHash,
        public ?array $permissions,
        public ?int $rateLimitPerMinute,
        public ?CarbonInterface $expiresAt,
        public ?CarbonInterface $revokedAt,
        public ?array $metadata,
    ) {}

    /**
     * Create a DTO instance from an array payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $permissions = null;

        if (array_key_exists('permissions', $data) && $data['permissions'] !== null) {
            /** @var list<string> $permissions */
            $permissions = array_values((array) $data['permissions']);
        }

        $expiresAt = null;

        if (array_key_exists('expires_at', $data)) {
            $expiresAt = $data['expires_at'] === null
                ? null
                : ($data['expires_at'] instanceof CarbonInterface
                    ? $data['expires_at']
                    : Carbon::parse($data['expires_at']));
        }

        $revokedAt = null;

        if (array_key_exists('revoked_at', $data)) {
            $revokedAt = $data['revoked_at'] === null
                ? null
                : ($data['revoked_at'] instanceof CarbonInterface
                    ? $data['revoked_at']
                    : Carbon::parse($data['revoked_at']));
        }

        return new self(
            userId: array_key_exists('user_id', $data)
                ? ($data['user_id'] !== null ? (string) $data['user_id'] : null)
                : null,
            name: array_key_exists('name', $data)
                ? ($data['name'] !== null ? (string) $data['name'] : null)
                : null,
            keyPrefix: array_key_exists('key_prefix', $data)
                ? ($data['key_prefix'] !== null ? (string) $data['key_prefix'] : null)
                : null,
            keyHash: array_key_exists('key_hash', $data)
                ? ($data['key_hash'] !== null ? (string) $data['key_hash'] : null)
                : null,
            permissions: $permissions,
            rateLimitPerMinute: array_key_exists('rate_limit_per_minute', $data)
                ? ($data['rate_limit_per_minute'] !== null ? (int) $data['rate_limit_per_minute'] : null)
                : null,
            expiresAt: $expiresAt,
            revokedAt: $revokedAt,
            metadata: array_key_exists('metadata', $data)
                ? ($data['metadata'] !== null ? (array) $data['metadata'] : null)
                : null,
        );
    }

    /**
     * Convert the DTO to model-fillable attributes for update.
     *
     * Only non-null properties are included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $attributes = [];

        if ($this->userId !== null) {
            $attributes['user_id'] = $this->userId;
        }

        if ($this->name !== null) {
            $attributes['name'] = $this->name;
        }

        if ($this->keyPrefix !== null) {
            $attributes['key_prefix'] = $this->keyPrefix;
        }

        if ($this->keyHash !== null) {
            $attributes['key_hash'] = $this->keyHash;
        }

        if ($this->permissions !== null) {
            $attributes['permissions'] = $this->permissions;
        }

        if ($this->rateLimitPerMinute !== null) {
            $attributes['rate_limit_per_minute'] = $this->rateLimitPerMinute;
        }

        if ($this->expiresAt !== null) {
            $attributes['expires_at'] = $this->expiresAt;
        }

        if ($this->revokedAt !== null) {
            $attributes['revoked_at'] = $this->revokedAt;
        }

        if ($this->metadata !== null) {
            $attributes['metadata'] = $this->metadata;
        }

        return $attributes;
    }

    /**
     * @param  list<string>  $permissions
     */
    public function withPermissions(array $permissions): self
    {
        return new self(
            userId: $this->userId,
            name: $this->name,
            keyPrefix: $this->keyPrefix,
            keyHash: $this->keyHash,
            permissions: $permissions,
            rateLimitPerMinute: $this->rateLimitPerMinute,
            expiresAt: $this->expiresAt,
            revokedAt: $this->revokedAt,
            metadata: $this->metadata,
        );
    }
}
