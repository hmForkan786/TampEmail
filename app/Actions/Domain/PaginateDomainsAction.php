<?php

declare(strict_types=1);

namespace App\Actions\Domain;

use App\DTOs\Domain\DomainFiltersData;
use App\Repositories\Contracts\DomainRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Retrieve a paginated list of domains matching the given filters.
 */
final class PaginateDomainsAction
{
    /**
     * @param DomainRepositoryInterface $domainRepository Domain persistence contract.
     */
    public function __construct(
        private readonly DomainRepositoryInterface $domainRepository,
    ) {}

    /**
     * Retrieve a paginated list of domains for the given filters.
     *
     * @param DomainFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated domain results.
     */
    public function execute(DomainFiltersData $filters): LengthAwarePaginator
    {
        return $this->domainRepository->paginate($filters);
    }
}
