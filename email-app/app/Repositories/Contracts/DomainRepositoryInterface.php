<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Domain\CreateDomainData;
use App\DTOs\Domain\DomainFiltersData;
use App\DTOs\Domain\UpdateDomainData;
use App\Models\Domain;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Contract for domain persistence operations.
 *
 * Common CRUD methods are inherited from BaseRepositoryInterface.
 * Module-specific lookups and Filter DTO pagination remain here.
 *
 * @extends BaseRepositoryInterface<Domain, CreateDomainData, UpdateDomainData>
 */
interface DomainRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find a domain by its unique domain hostname.
     *
     * @param string $domain Unique domain hostname.
     *
     * @return Domain|null The matching domain, if found.
     */
    public function findByDomain(string $domain): ?Domain;

    /**
     * Retrieve a paginated list of domains matching the given filters.
     *
     * @param DomainFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated domain results.
     */
    public function paginate(DomainFiltersData $filters): LengthAwarePaginator;
}
