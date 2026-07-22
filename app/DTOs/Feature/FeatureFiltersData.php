<?php

declare(strict_types=1);

namespace App\DTOs\Feature;

use App\Enums\ValueType;

/**
 * Filter and pagination state for querying features.
 */
final readonly class FeatureFiltersData
{
    /**
     * @param string|null $category Filter by feature category.
     * @param ValueType|null $valueType Filter by feature value type.
     * @param bool|null $isActive Filter by active status.
     * @param string|null $search Free-text search term.
     * @param int $perPage Number of results per page.
     * @param string $sortBy Column to sort by.
     * @param string $sortDirection Sort direction (asc or desc).
     */
    public function __construct(
        public ?string $category,
        public ?ValueType $valueType,
        public ?bool $isActive,
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
        $valueType = null;

        if (array_key_exists('value_type', $data) && $data['value_type'] !== null) {
            $valueType = $data['value_type'] instanceof ValueType
                ? $data['value_type']
                : ValueType::from((string) $data['value_type']);
        }

        return new self(
            category: array_key_exists('category', $data)
                ? ($data['category'] !== null ? (string) $data['category'] : null)
                : null,
            valueType: $valueType,
            isActive: array_key_exists('is_active', $data)
                ? ($data['is_active'] !== null ? (bool) $data['is_active'] : null)
                : null,
            search: array_key_exists('search', $data)
                ? ($data['search'] !== null ? trim((string) $data['search']) : null)
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
