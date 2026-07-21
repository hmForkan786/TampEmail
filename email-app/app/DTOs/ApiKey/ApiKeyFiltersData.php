<?php

declare(strict_types=1);

namespace App\DTOs\ApiKey;

/**
 * Filter and pagination state for querying API keys.
 */
final readonly class ApiKeyFiltersData
{
    /**
     * @param string|null $userId Filter by owning user UUID.
     * @param string|null $keyPrefix Filter by public key prefix.
     * @param string|null $search Free-text search term.
     * @param bool|null $isRevoked Filter by revocation status.
     * @param bool|null $isExpired Filter by computed expiration status.
     * @param int $perPage Number of results per page.
     * @param string $sortBy Column to sort by.
     * @param string $sortDirection Sort direction (asc or desc).
     */
    public function __construct(
        public ?string $userId,
        public ?string $keyPrefix,
        public ?string $search,
        public ?bool $isRevoked,
        public ?bool $isExpired,
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
            userId: array_key_exists('user_id', $data)
                ? ($data['user_id'] !== null ? (string) $data['user_id'] : null)
                : null,
            keyPrefix: array_key_exists('key_prefix', $data)
                ? ($data['key_prefix'] !== null ? (string) $data['key_prefix'] : null)
                : null,
            search: array_key_exists('search', $data)
                ? ($data['search'] !== null ? trim((string) $data['search']) : null)
                : null,
            isRevoked: array_key_exists('is_revoked', $data)
                ? ($data['is_revoked'] !== null ? (bool) $data['is_revoked'] : null)
                : null,
            isExpired: array_key_exists('is_expired', $data)
                ? ($data['is_expired'] !== null ? (bool) $data['is_expired'] : null)
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
