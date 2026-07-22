<?php

declare(strict_types=1);

namespace App\DTOs\Domain;

/**
 * Immutable input data for partially updating a domain record.
 */
final readonly class UpdateDomainData
{
    /**
     * @param string|null $domain Unique domain hostname.
     * @param string|null $displayName Human-readable domain display name.
     * @param string|null $description Optional domain description.
     * @param bool|null $isActive Whether the domain is active.
     * @param bool|null $isPublic Whether the domain is publicly visible.
     * @param bool|null $allowRegistration Whether new mailboxes may be registered.
     * @param bool|null $isHealthy Whether the domain is currently healthy.
     * @param int|null $priority Sorting priority for domain selection.
     * @param int|null $maxMailboxes Optional mailbox capacity limit.
     * @param int|null $retentionHours Mailbox retention duration in hours.
     * @param array<string, mixed>|null $metadata Optional additional domain metadata.
     */
    public function __construct(
        public ?string $domain,
        public ?string $displayName,
        public ?string $description,
        public ?bool $isActive,
        public ?bool $isPublic,
        public ?bool $allowRegistration,
        public ?bool $isHealthy,
        public ?int $priority,
        public ?int $maxMailboxes,
        public ?int $retentionHours,
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
            domain: array_key_exists('domain', $data)
                ? ($data['domain'] !== null ? (string) $data['domain'] : null)
                : null,
            displayName: array_key_exists('display_name', $data)
                ? ($data['display_name'] !== null ? (string) $data['display_name'] : null)
                : null,
            description: array_key_exists('description', $data)
                ? ($data['description'] !== null ? (string) $data['description'] : null)
                : null,
            isActive: array_key_exists('is_active', $data)
                ? ($data['is_active'] !== null ? (bool) $data['is_active'] : null)
                : null,
            isPublic: array_key_exists('is_public', $data)
                ? ($data['is_public'] !== null ? (bool) $data['is_public'] : null)
                : null,
            allowRegistration: array_key_exists('allow_registration', $data)
                ? ($data['allow_registration'] !== null ? (bool) $data['allow_registration'] : null)
                : null,
            isHealthy: array_key_exists('is_healthy', $data)
                ? ($data['is_healthy'] !== null ? (bool) $data['is_healthy'] : null)
                : null,
            priority: array_key_exists('priority', $data)
                ? ($data['priority'] !== null ? (int) $data['priority'] : null)
                : null,
            maxMailboxes: array_key_exists('max_mailboxes', $data)
                ? ($data['max_mailboxes'] !== null ? (int) $data['max_mailboxes'] : null)
                : null,
            retentionHours: array_key_exists('retention_hours', $data)
                ? ($data['retention_hours'] !== null ? (int) $data['retention_hours'] : null)
                : null,
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

        if ($this->domain !== null) {
            $attributes['domain'] = $this->domain;
        }

        if ($this->displayName !== null) {
            $attributes['display_name'] = $this->displayName;
        }

        if ($this->description !== null) {
            $attributes['description'] = $this->description;
        }

        if ($this->isActive !== null) {
            $attributes['is_active'] = $this->isActive;
        }

        if ($this->isPublic !== null) {
            $attributes['is_public'] = $this->isPublic;
        }

        if ($this->allowRegistration !== null) {
            $attributes['allow_registration'] = $this->allowRegistration;
        }

        if ($this->isHealthy !== null) {
            $attributes['is_healthy'] = $this->isHealthy;
        }

        if ($this->priority !== null) {
            $attributes['priority'] = $this->priority;
        }

        if ($this->maxMailboxes !== null) {
            $attributes['max_mailboxes'] = $this->maxMailboxes;
        }

        if ($this->retentionHours !== null) {
            $attributes['retention_hours'] = $this->retentionHours;
        }

        if ($this->metadata !== null) {
            $attributes['metadata'] = $this->metadata;
        }

        return $attributes;
    }
}
