<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DTOs\Domain\CreateDomainData;
use App\DTOs\Domain\DomainFiltersData;
use App\DTOs\Domain\UpdateDomainData;
use App\Models\Domain;
use App\Repositories\Contracts\DomainRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent-backed persistence and query implementation for domains.
 *
 * Common CRUD operations are inherited from BaseEloquentRepository.
 *
 * @extends BaseEloquentRepository<Domain, CreateDomainData, UpdateDomainData>
 */
final class EloquentDomainRepository extends BaseEloquentRepository implements DomainRepositoryInterface
{
    /**
     * @return Domain
     */
    protected function model(): Domain
    {
        return new Domain;
    }

    /**
     * Find a domain by its unique domain hostname.
     *
     * @param string $domain Unique domain hostname.
     *
     * @return Domain|null The matching domain, if found.
     */
    public function findByDomain(string $domain): ?Domain
    {
        return $this->model()->newQuery()
            ->where('domain', $domain)
            ->first();
    }

    /**
     * Retrieve a paginated list of domains matching the given filters.
     *
     * @param DomainFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated domain results.
     */
    public function paginate(DomainFiltersData $filters): LengthAwarePaginator
    {
        $query = $this->model()->newQuery();

        if ($filters->isActive === true) {
            $query->where('is_active', true);
        }

        if ($filters->isActive === false) {
            $query->where('is_active', false);
        }

        if ($filters->isPublic === true) {
            $query->where('is_public', true);
        }

        if ($filters->isPublic === false) {
            $query->where('is_public', false);
        }

        if ($filters->allowRegistration === true) {
            $query->where('allow_registration', true);
        }

        if ($filters->allowRegistration === false) {
            $query->where('allow_registration', false);
        }

        if ($filters->isHealthy === true) {
            $query->where('is_healthy', true);
        }

        if ($filters->isHealthy === false) {
            $query->where('is_healthy', false);
        }

        if ($filters->hasSearch()) {
            $search = $filters->search;

            $query->where(function ($query) use ($search): void {
                $query->where('domain', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderBy($filters->sortBy, $filters->sortDirection)
            ->paginate($filters->perPage);
    }
}
