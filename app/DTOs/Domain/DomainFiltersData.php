<?php

declare(strict_types=1);

namespace App\DTOs\Domain;

/**
 * Filter and pagination state for querying domains.
 */
final readonly class DomainFiltersData
{
    /**
     * @param string|null $search Free-text search term.
     * @param bool|null $isActive Filter by active status.
     * @param bool|null $isPublic Filter by public visibility.
     * @param bool|null $allowRegistration Filter by registration allowance.
     * @param bool|null $isHealthy Filter by health status.
     * @param int $perPage Number of results per page.
     * @param string $sortBy Column to sort by.
     * @param string $sortDirection Sort direction (asc or desc).
     */
    public function __construct(
        public ?string $search,
        public ?bool $isActive,
        public ?bool $isPublic,
        public ?bool $allowRegistration,
        public ?bool $isHealthy,
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
        return new self(
            search: array_key_exists('search', $data)
                ? ($data['search'] !== null ? (string) $data['search'] : null)
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
