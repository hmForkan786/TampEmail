<?php

declare(strict_types=1);

namespace App\DTOs\ApiKey;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Immutable input data for creating a new API key record.
 */
final readonly class CreateApiKeyData
{
    /**
     * @param string $userId Owning user UUID.
     * @param string $name Human-readable API key label.
     * @param string $keyPrefix Short public prefix for key identification.
     * @param string $keyHash Hashed secret for the API key.
     * @param list<string>|null $permissions Optional granted permission scopes.
     * @param int $rateLimitPerMinute Maximum requests allowed per minute.
     * @param CarbonInterface|null $expiresAt Optional expiration timestamp.
     * @param CarbonInterface|null $revokedAt Optional revocation timestamp.
     * @param array<string, mixed>|null $metadata Optional additional API key metadata.
     */
    public function __construct(
        public string $userId,
        public string $name,
        public string $keyPrefix,
        public string $keyHash,
        public ?array $permissions,
        public int $rateLimitPerMinute,
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
        $expiresAt = null;

        if (array_key_exists('expires_at', $data) && $data['expires_at'] !== null) {
            $expiresAt = $data['expires_at'] instanceof CarbonInterface
                ? $data['expires_at']
                : Carbon::parse($data['expires_at']);
        }

        $revokedAt = null;

        if (array_key_exists('revoked_at', $data) && $data['revoked_at'] !== null) {
            $revokedAt = $data['revoked_at'] instanceof CarbonInterface
                ? $data['revoked_at']
                : Carbon::parse($data['revoked_at']);
        }

        $permissions = null;

        if (isset($data['permissions'])) {
            /** @var list<string> $permissions */
            $permissions = array_values((array) $data['permissions']);
        }

        return new self(
            userId: (string) $data['user_id'],
            name: (string) $data['name'],
            keyPrefix: (string) $data['key_prefix'],
            keyHash: (string) $data['key_hash'],
            permissions: $permissions,
            rateLimitPerMinute: array_key_exists('rate_limit_per_minute', $data)
                ? (int) $data['rate_limit_per_minute']
                : 60,
            expiresAt: $expiresAt,
            revokedAt: $revokedAt,
            metadata: isset($data['metadata']) ? (array) $data['metadata'] : null,
        );
    }

    /**
     * Convert the DTO to model-fillable attributes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'name' => $this->name,
            'key_prefix' => $this->keyPrefix,
            'key_hash' => $this->keyHash,
            'permissions' => $this->permissions,
            'rate_limit_per_minute' => $this->rateLimitPerMinute,
            'expires_at' => $this->expiresAt,
            'revoked_at' => $this->revokedAt,
            'metadata' => $this->metadata,
        ];
    }
}
