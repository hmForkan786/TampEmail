<?php

declare(strict_types=1);

namespace App\DTOs\Inbox;

use App\Enums\InboxType;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Immutable input data for creating a new inbox.
 */
readonly class CreateInboxData
{
    /**
     * @param string $domainId Domain UUID for the inbox.
     * @param string|null $userId Optional owning user UUID.
     * @param string $localPart Local part of the inbox address.
     * @param string $fullAddress Full unique inbox address.
     * @param string|null $displayName Optional display name.
     * @param InboxType $inboxType Inbox lifecycle classification.
     * @param CarbonInterface|null $expiresAt Optional expiration timestamp.
     * @param array<string, mixed>|null $metadata Optional extra configuration.
     */
    public function __construct(
        public string $domainId,
        public ?string $userId,
        public string $localPart,
        public string $fullAddress,
        public ?string $displayName,
        public InboxType $inboxType,
        public ?CarbonInterface $expiresAt,
        public ?array $metadata,
    ) {}

    /**
     * Create a DTO instance from an array payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $inboxType = $data['inbox_type'] ?? InboxType::Temporary;

        if (! $inboxType instanceof InboxType) {
            $inboxType = InboxType::from((string) $inboxType);
        }

        $expiresAt = $data['expires_at'] ?? null;

        if ($expiresAt !== null && ! $expiresAt instanceof CarbonInterface) {
            $expiresAt = Carbon::parse($expiresAt);
        }

        return new self(
            domainId: (string) $data['domain_id'],
            userId: isset($data['user_id']) ? (string) $data['user_id'] : null,
            localPart: (string) $data['local_part'],
            fullAddress: (string) $data['full_address'],
            displayName: isset($data['display_name']) ? (string) $data['display_name'] : null,
            inboxType: $inboxType,
            expiresAt: $expiresAt,
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
            'domain_id' => $this->domainId,
            'user_id' => $this->userId,
            'local_part' => $this->localPart,
            'full_address' => $this->fullAddress,
            'display_name' => $this->displayName,
            'inbox_type' => $this->inboxType->value,
            'expires_at' => $this->expiresAt,
            'metadata' => $this->metadata,
        ];
    }
}
