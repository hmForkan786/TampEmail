<?php

declare(strict_types=1);

namespace App\DTOs\Email;

use App\Enums\ProcessingStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Filter and pagination state for querying emails.
 */
final readonly class EmailFiltersData
{
    /**
     * @param string|null $inboxId Filter by inbox UUID.
     * @param ProcessingStatus|null $processingStatus Filter by processing status.
     * @param string|null $senderEmail Filter by sender email address.
     * @param string|null $recipientEmail Filter by recipient email address.
     * @param bool|null $hasAttachments Filter by attachment presence.
     * @param CarbonInterface|null $receivedFrom Filter by received-at start timestamp.
     * @param CarbonInterface|null $receivedTo Filter by received-at end timestamp.
     * @param string|null $search Free-text search term.
     * @param int $perPage Number of results per page.
     * @param string $sortBy Column to sort by.
     * @param string $sortDirection Sort direction (asc or desc).
     */
    public function __construct(
        public ?string $inboxId,
        public ?ProcessingStatus $processingStatus,
        public ?string $senderEmail,
        public ?string $recipientEmail,
        public ?bool $hasAttachments,
        public ?CarbonInterface $receivedFrom,
        public ?CarbonInterface $receivedTo,
        public ?string $search,
        public int $perPage,
        public string $sortBy,
        public string $sortDirection,
    ) {}

    /**
     * Create a filter DTO instance from an array payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $processingStatus = null;

        if (array_key_exists('processing_status', $data) && $data['processing_status'] !== null) {
            $processingStatus = $data['processing_status'] instanceof ProcessingStatus
                ? $data['processing_status']
                : ProcessingStatus::from((string) $data['processing_status']);
        }

        $receivedFrom = null;

        if (array_key_exists('received_from', $data) && $data['received_from'] !== null) {
            $receivedFrom = $data['received_from'] instanceof CarbonInterface
                ? $data['received_from']
                : Carbon::parse($data['received_from']);
        }

        $receivedTo = null;

        if (array_key_exists('received_to', $data) && $data['received_to'] !== null) {
            $receivedTo = $data['received_to'] instanceof CarbonInterface
                ? $data['received_to']
                : Carbon::parse($data['received_to']);
        }

        return new self(
            inboxId: array_key_exists('inbox_id', $data)
                ? ($data['inbox_id'] !== null ? (string) $data['inbox_id'] : null)
                : null,
            processingStatus: $processingStatus,
            senderEmail: array_key_exists('sender_email', $data)
                ? ($data['sender_email'] !== null ? (string) $data['sender_email'] : null)
                : null,
            recipientEmail: array_key_exists('recipient_email', $data)
                ? ($data['recipient_email'] !== null ? (string) $data['recipient_email'] : null)
                : null,
            hasAttachments: array_key_exists('has_attachments', $data)
                ? ($data['has_attachments'] !== null ? (bool) $data['has_attachments'] : null)
                : null,
            receivedFrom: $receivedFrom,
            receivedTo: $receivedTo,
            search: array_key_exists('search', $data)
                ? ($data['search'] !== null ? (string) $data['search'] : null)
                : null,
            perPage: array_key_exists('per_page', $data)
                ? (int) $data['per_page']
                : 15,
            sortBy: array_key_exists('sort_by', $data)
                ? (string) $data['sort_by']
                : 'created_at',
            sortDirection: array_key_exists('sort_direction', $data)
                ? (string) $data['sort_direction']
                : 'desc',
        );
    }

    /**
     * Determine whether a search term is present.
     */
    public function hasSearch(): bool
    {
        return $this->search !== null && $this->search !== '';
    }

    /**
     * Determine whether a date range filter is present.
     */
    public function hasDateRange(): bool
    {
        return $this->receivedFrom !== null
            || $this->receivedTo !== null;
    }

    /**
     * Determine whether sorting parameters are present.
     */
    public function hasSorting(): bool
    {
        return $this->sortBy !== ''
            && $this->sortDirection !== '';
    }

    /**
     * Get pagination settings for the query.
     *
     * @return array{per_page: int}
     */
    public function pagination(): array
    {
        return [
            'per_page' => $this->perPage,
        ];
    }
}
