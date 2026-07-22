<?php

declare(strict_types=1);

namespace App\DTOs\Inbox;

use App\Enums\InboxType;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Immutable input data for partially updating an inbox.
 */
readonly class UpdateInboxData
{
    /**
     * @param string|null $domainId Domain UUID for the inbox.
     * @param string|null $userId Optional owning user UUID.
     * @param string|null $localPart Local part of the inbox address.
     * @param string|null $fullAddress Full unique inbox address.
     * @param string|null $displayName Optional display name.
     * @param InboxType|null $inboxType Inbox lifecycle classification.
     * @param CarbonInterface|null $expiresAt Optional expiration timestamp.
     * @param array<string, mixed>|null $metadata Optional extra configuration.
     */
    public function __construct(
        public ?string $domainId = null,
        public ?string $userId = null,
        public ?string $localPart = null,
        public ?string $fullAddress = null,
        public ?string $displayName = null,
        public ?InboxType $inboxType = null,
        public ?CarbonInterface $expiresAt = null,
        public ?array $metadata = null,
    ) {}

    /**
     * Create a DTO instance from an array payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $inboxType = null;

        if (array_key_exists('inbox_type', $data)) {
            $inboxType = $data['inbox_type'] instanceof InboxType
                ? $data['inbox_type']
                : InboxType::from((string) $data['inbox_type']);
        }

        $expiresAt = null;

        if (array_key_exists('expires_at', $data)) {
            $expiresAt = $data['expires_at'] === null
                ? null
                : ($data['expires_at'] instanceof CarbonInterface
                    ? $data['expires_at']
                    : Carbon::parse($data['expires_at']));
        }

        return new self(
            domainId: array_key_exists('domain_id', $data) ? (string) $data['domain_id'] : null,
            userId: array_key_exists('user_id', $data)
                ? ($data['user_id'] !== null ? (string) $data['user_id'] : null)
                : null,
            localPart: array_key_exists('local_part', $data) ? (string) $data['local_part'] : null,
            fullAddress: array_key_exists('full_address', $data) ? (string) $data['full_address'] : null,
            displayName: array_key_exists('display_name', $data)
                ? ($data['display_name'] !== null ? (string) $data['display_name'] : null)
                : null,
            inboxType: $inboxType,
            expiresAt: $expiresAt,
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

        if ($this->domainId !== null) {
            $attributes['domain_id'] = $this->domainId;
        }

        if ($this->userId !== null) {
            $attributes['user_id'] = $this->userId;
        }

        if ($this->localPart !== null) {
            $attributes['local_part'] = $this->localPart;
        }

        if ($this->fullAddress !== null) {
            $attributes['full_address'] = $this->fullAddress;
        }

        if ($this->displayName !== null) {
            $attributes['display_name'] = $this->displayName;
        }

        if ($this->inboxType !== null) {
            $attributes['inbox_type'] = $this->inboxType->value;
        }

        if ($this->expiresAt !== null) {
            $attributes['expires_at'] = $this->expiresAt;
        }

        if ($this->metadata !== null) {
            $attributes['metadata'] = $this->metadata;
        }

        return $attributes;
    }
}
