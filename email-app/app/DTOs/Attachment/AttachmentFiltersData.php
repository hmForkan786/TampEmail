<?php

declare(strict_types=1);

namespace App\DTOs\Attachment;

use App\Enums\AttachmentScanStatus;

/**
 * Filter and pagination state for querying attachments.
 */
final readonly class AttachmentFiltersData
{
    /**
     * @param string|null $emailId Filter by email UUID.
     * @param AttachmentScanStatus|null $scanStatus Filter by scan status.
     * @param bool|null $isSafe Filter by safety flag.
     * @param string|null $mimeType Filter by MIME type.
     * @param string|null $extension Filter by file extension.
     * @param string|null $search Free-text search term.
     * @param int $perPage Number of results per page.
     * @param string $sortBy Column to sort by.
     * @param string $sortDirection Sort direction (asc or desc).
     */
    public function __construct(
        public ?string $emailId,
        public ?AttachmentScanStatus $scanStatus,
        public ?bool $isSafe,
        public ?string $mimeType,
        public ?string $extension,
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
        $scanStatus = null;

        if (array_key_exists('scan_status', $data) && $data['scan_status'] !== null) {
            $scanStatus = $data['scan_status'] instanceof AttachmentScanStatus
                ? $data['scan_status']
                : AttachmentScanStatus::from((string) $data['scan_status']);
        }

        return new self(
            emailId: array_key_exists('email_id', $data)
                ? ($data['email_id'] !== null ? (string) $data['email_id'] : null)
                : null,
            scanStatus: $scanStatus,
            isSafe: array_key_exists('is_safe', $data)
                ? ($data['is_safe'] !== null ? (bool) $data['is_safe'] : null)
                : null,
            mimeType: array_key_exists('mime_type', $data)
                ? ($data['mime_type'] !== null ? (string) $data['mime_type'] : null)
                : null,
            extension: array_key_exists('extension', $data)
                ? ($data['extension'] !== null ? (string) $data['extension'] : null)
                : null,
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
