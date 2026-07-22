<?php

declare(strict_types=1);

namespace App\DTOs\MailServer;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Immutable input data for partially updating a mail server record.
 */
final readonly class UpdateMailServerData
{
    /**
     * @param string|null $name Human-readable mail server label.
     * @param string|null $hostname Server hostname for the ingestion endpoint.
     * @param string|null $provider Inbound provider identifier (validated via MailProvider enum).
     * @param string|null $protocol Ingestion transport protocol (validated via MailProtocol enum).
     * @param bool|null $isActive Whether the mail server is active.
     * @param int|null $priority Sorting priority for server selection.
     * @param int|null $maxConnections Maximum simultaneous connections allowed.
     * @param int|null $timeoutSeconds Connection timeout in seconds.
     * @param CarbonInterface|null $lastHealthCheckAt Optional last health check timestamp.
     * @param array<string, mixed>|null $metadata Optional additional mail server metadata.
     * @param string|null $poolKey Optional entitlement pool key for server selection.
     * @param int|null $maxInboxes Optional inbox capacity limit; null means unlimited / omit.
     */
    public function __construct(
        public ?string $name,
        public ?string $hostname,
        public ?string $provider,
        public ?string $protocol,
        public ?bool $isActive,
        public ?int $priority,
        public ?int $maxConnections,
        public ?int $timeoutSeconds,
        public ?CarbonInterface $lastHealthCheckAt,
        public ?array $metadata,
        public ?string $poolKey = null,
        public ?int $maxInboxes = null,
    ) {}

    /**
     * Create a DTO instance from an array payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $lastHealthCheckAt = null;

        if (array_key_exists('last_health_check_at', $data) && $data['last_health_check_at'] !== null) {
            $lastHealthCheckAt = $data['last_health_check_at'] instanceof CarbonInterface
                ? $data['last_health_check_at']
                : Carbon::parse($data['last_health_check_at']);
        }

        return new self(
            name: array_key_exists('name', $data)
                ? ($data['name'] !== null ? (string) $data['name'] : null)
                : null,
            hostname: array_key_exists('hostname', $data)
                ? ($data['hostname'] !== null ? (string) $data['hostname'] : null)
                : null,
            provider: array_key_exists('provider', $data)
                ? ($data['provider'] !== null ? (string) $data['provider'] : null)
                : null,
            protocol: array_key_exists('protocol', $data)
                ? ($data['protocol'] !== null ? (string) $data['protocol'] : null)
                : null,
            isActive: array_key_exists('is_active', $data)
                ? ($data['is_active'] !== null ? (bool) $data['is_active'] : null)
                : null,
            priority: array_key_exists('priority', $data)
                ? ($data['priority'] !== null ? (int) $data['priority'] : null)
                : null,
            maxConnections: array_key_exists('max_connections', $data)
                ? ($data['max_connections'] !== null ? (int) $data['max_connections'] : null)
                : null,
            timeoutSeconds: array_key_exists('timeout_seconds', $data)
                ? ($data['timeout_seconds'] !== null ? (int) $data['timeout_seconds'] : null)
                : null,
            lastHealthCheckAt: $lastHealthCheckAt,
            metadata: array_key_exists('metadata', $data)
                ? ($data['metadata'] !== null ? (array) $data['metadata'] : null)
                : null,
            poolKey: array_key_exists('pool_key', $data)
                ? ($data['pool_key'] !== null ? (string) $data['pool_key'] : null)
                : null,
            maxInboxes: array_key_exists('max_inboxes', $data)
                ? ($data['max_inboxes'] !== null ? (int) $data['max_inboxes'] : null)
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

        if ($this->name !== null) {
            $attributes['name'] = $this->name;
        }

        if ($this->hostname !== null) {
            $attributes['hostname'] = $this->hostname;
        }

        if ($this->provider !== null) {
            $attributes['provider'] = $this->provider;
        }

        if ($this->protocol !== null) {
            $attributes['protocol'] = $this->protocol;
        }

        if ($this->isActive !== null) {
            $attributes['is_active'] = $this->isActive;
        }

        if ($this->priority !== null) {
            $attributes['priority'] = $this->priority;
        }

        if ($this->maxConnections !== null) {
            $attributes['max_connections'] = $this->maxConnections;
        }

        if ($this->timeoutSeconds !== null) {
            $attributes['timeout_seconds'] = $this->timeoutSeconds;
        }

        if ($this->lastHealthCheckAt !== null) {
            $attributes['last_health_check_at'] = $this->lastHealthCheckAt;
        }

        if ($this->metadata !== null) {
            $attributes['metadata'] = $this->metadata;
        }

        if ($this->poolKey !== null) {
            $attributes['pool_key'] = $this->poolKey;
        }

        if ($this->maxInboxes !== null) {
            $attributes['max_inboxes'] = $this->maxInboxes;
        }

        return $attributes;
    }

    public function withProvisioningFields(?string $poolKey, ?int $maxInboxes): self
    {
        return new self(
            name: $this->name,
            hostname: $this->hostname,
            provider: $this->provider,
            protocol: $this->protocol,
            isActive: $this->isActive,
            priority: $this->priority,
            maxConnections: $this->maxConnections,
            timeoutSeconds: $this->timeoutSeconds,
            lastHealthCheckAt: $this->lastHealthCheckAt,
            metadata: $this->metadata,
            poolKey: $poolKey,
            maxInboxes: $maxInboxes,
        );
    }
}
