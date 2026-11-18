<?php

declare(strict_types=1);

namespace App\DTOs\MailServer;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Immutable input data for creating a new mail server record.
 */
final readonly class CreateMailServerData
{
    /**
     * @param  string  $name  Human-readable mail server label.
     * @param  string  $hostname  Server hostname for the ingestion endpoint.
     * @param  string  $provider  Inbound provider identifier (validated via MailProvider enum).
     * @param  string  $protocol  Ingestion transport protocol (validated via MailProtocol enum).
     * @param  bool  $isActive  Whether the mail server is active.
     * @param  int  $priority  Sorting priority for server selection.
     * @param  int  $maxConnections  Maximum simultaneous connections allowed.
     * @param  int  $timeoutSeconds  Connection timeout in seconds.
     * @param  CarbonInterface|null  $lastHealthCheckAt  Optional last health check timestamp.
     * @param  array<string, mixed>|null  $metadata  Optional additional mail server metadata.
     * @param  string|null  $poolKey  Optional entitlement pool key for server selection.
     * @param  int|null  $maxInboxes  Optional inbox capacity limit; null means unlimited.
     */
    public function __construct(
        public string $name,
        public string $hostname,
        public string $provider,
        public string $protocol,
        public bool $isActive,
        public int $priority,
        public int $maxConnections,
        public int $timeoutSeconds,
        public ?CarbonInterface $lastHealthCheckAt,
        public ?array $metadata,
        public ?string $poolKey = null,
        public ?int $maxInboxes = null,
        public ?string $operationalStatus = null,
        public ?int $maxThroughput = null,
    ) {}

    /**
     * Create a DTO instance from an array payload.
     *
     * @param  array<string, mixed>  $data
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
            name: (string) $data['name'],
            hostname: (string) $data['hostname'],
            provider: (string) $data['provider'],
            protocol: (string) $data['protocol'],
            isActive: array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : true,
            priority: array_key_exists('priority', $data)
                ? (int) $data['priority']
                : 0,
            maxConnections: array_key_exists('max_connections', $data)
                ? (int) $data['max_connections']
                : 100,
            timeoutSeconds: array_key_exists('timeout_seconds', $data)
                ? (int) $data['timeout_seconds']
                : 30,
            lastHealthCheckAt: $lastHealthCheckAt,
            metadata: isset($data['metadata']) ? (array) $data['metadata'] : null,
            poolKey: array_key_exists('pool_key', $data)
                ? ($data['pool_key'] !== null ? (string) $data['pool_key'] : null)
                : null,
            maxInboxes: array_key_exists('max_inboxes', $data)
                ? ($data['max_inboxes'] !== null ? (int) $data['max_inboxes'] : null)
                : null,
            operationalStatus: array_key_exists('operational_status', $data)
                ? ($data['operational_status'] !== null ? (string) $data['operational_status'] : null)
                : null,
            maxThroughput: array_key_exists('max_throughput', $data)
                ? ($data['max_throughput'] !== null ? (int) $data['max_throughput'] : null)
                : null,
        );
    }

    /**
     * Convert the DTO to model-fillable attributes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $attributes = [
            'name' => $this->name,
            'hostname' => $this->hostname,
            'provider' => $this->provider,
            'protocol' => $this->protocol,
            'is_active' => $this->isActive,
            'priority' => $this->priority,
            'max_connections' => $this->maxConnections,
            'timeout_seconds' => $this->timeoutSeconds,
            'last_health_check_at' => $this->lastHealthCheckAt,
            'metadata' => $this->metadata,
            'pool_key' => $this->poolKey,
            'max_inboxes' => $this->maxInboxes,
            'max_throughput' => $this->maxThroughput,
        ];

        if ($this->operationalStatus !== null) {
            $attributes['operational_status'] = $this->operationalStatus;
            $attributes['is_active'] = $this->operationalStatus === 'active';
        } elseif (! $this->isActive) {
            $attributes['operational_status'] = 'disabled';
        } else {
            $attributes['operational_status'] = 'active';
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
            operationalStatus: $this->operationalStatus,
            maxThroughput: $this->maxThroughput,
        );
    }
}
