<?php

declare(strict_types=1);

namespace App\DTOs\Domain;

/**
 * Immutable input data for creating a new domain record.
 */
final readonly class CreateDomainData
{
    /**
     * @param string $domain Unique domain hostname.
     * @param string $displayName Human-readable domain display name.
     * @param string|null $description Optional domain description.
     * @param bool $isActive Whether the domain is active.
     * @param bool $isPublic Whether the domain is publicly visible.
     * @param bool $allowRegistration Whether new mailboxes may be registered.
     * @param bool $isHealthy Whether the domain is currently healthy.
     * @param int $priority Sorting priority for domain selection.
     * @param int|null $maxMailboxes Optional mailbox capacity limit.
     * @param int $retentionHours Mailbox retention duration in hours.
     * @param array<string, mixed>|null $metadata Optional additional domain metadata.
     */
    public function __construct(
        public string $domain,
        public string $displayName,
        public ?string $description,
        public bool $isActive,
        public bool $isPublic,
        public bool $allowRegistration,
        public bool $isHealthy,
        public int $priority,
        public ?int $maxMailboxes,
        public int $retentionHours,
        public ?array $metadata,
    ) {}

    /**
     * Create a DTO instance from an array payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            domain: (string) $data['domain'],
            displayName: (string) $data['display_name'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            isActive: array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : true,
            isPublic: array_key_exists('is_public', $data)
                ? (bool) $data['is_public']
                : true,
            allowRegistration: array_key_exists('allow_registration', $data)
                ? (bool) $data['allow_registration']
                : true,
            isHealthy: array_key_exists('is_healthy', $data)
                ? (bool) $data['is_healthy']
                : true,
            priority: array_key_exists('priority', $data)
                ? (int) $data['priority']
                : 0,
            maxMailboxes: array_key_exists('max_mailboxes', $data)
                ? ($data['max_mailboxes'] !== null ? (int) $data['max_mailboxes'] : null)
                : null,
            retentionHours: array_key_exists('retention_hours', $data)
                ? (int) $data['retention_hours']
                : 24,
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
            'domain' => $this->domain,
            'display_name' => $this->displayName,
            'description' => $this->description,
            'is_active' => $this->isActive,
            'is_public' => $this->isPublic,
            'allow_registration' => $this->allowRegistration,
            'is_healthy' => $this->isHealthy,
            'priority' => $this->priority,
            'max_mailboxes' => $this->maxMailboxes,
            'retention_hours' => $this->retentionHours,
            'metadata' => $this->metadata,
        ];
    }
}
