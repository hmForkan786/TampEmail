<?php

declare(strict_types=1);

namespace App\DTOs\Inbox;

use App\Enums\InboxType;

/**
 * Filter and pagination state for querying inboxes.
 */
readonly class InboxFiltersData
{
    /**
     * @param string|null $userId Filter by owning user UUID.
     * @param string|null $domainId Filter by domain UUID.
     * @param InboxType|null $inboxType Filter by inbox type.
     * @param bool|null $isActive Filter by active status.
     * @param bool|null $isExpired Filter by expired status.
     * @param string|null $search Free-text search term.
     * @param int|null $perPage Number of results per page.
     * @param string|null $sortBy Column to sort by.
     * @param string|null $sortDirection Sort direction (asc or desc).
     */
    public function __construct(
        public ?string $userId = null,
        public ?string $domainId = null,
        public ?InboxType $inboxType = null,
        public ?bool $isActive = null,
        public ?bool $isExpired = null,
        public ?string $search = null,
        public ?int $perPage = null,
        public ?string $sortBy = null,
        public ?string $sortDirection = null,
    ) {}

    /**
     * Create a filter DTO instance from an array payload.
     *
     * @param array<string, mixed> $filters
     */
    public static function fromArray(array $filters): self
    {
        $inboxType = null;

        if (array_key_exists('inbox_type', $filters) && $filters['inbox_type'] !== null) {
            $inboxType = $filters['inbox_type'] instanceof InboxType
                ? $filters['inbox_type']
                : InboxType::from((string) $filters['inbox_type']);
        }

        return new self(
            userId: array_key_exists('user_id', $filters)
                ? ($filters['user_id'] !== null ? (string) $filters['user_id'] : null)
                : null,
            domainId: array_key_exists('domain_id', $filters)
                ? ($filters['domain_id'] !== null ? (string) $filters['domain_id'] : null)
                : null,
            inboxType: $inboxType,
            isActive: array_key_exists('is_active', $filters)
                ? ($filters['is_active'] !== null ? (bool) $filters['is_active'] : null)
                : null,
            isExpired: array_key_exists('is_expired', $filters)
                ? ($filters['is_expired'] !== null ? (bool) $filters['is_expired'] : null)
                : null,
            search: array_key_exists('search', $filters)
                ? ($filters['search'] !== null ? (string) $filters['search'] : null)
                : null,
            perPage: array_key_exists('per_page', $filters)
                ? (int) $filters['per_page']
                : 15,
            sortBy: array_key_exists('sort_by', $filters)
                ? (string) $filters['sort_by']
                : 'created_at',
            sortDirection: array_key_exists('sort_direction', $filters)
                ? (string) $filters['sort_direction']
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
        return $this->sortBy !== null
            && $this->sortBy !== ''
            && $this->sortDirection !== null
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
            'per_page' => $this->perPage ?? 15,
        ];
    }
}
