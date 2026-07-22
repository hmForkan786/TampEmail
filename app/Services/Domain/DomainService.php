<?php

declare(strict_types=1);

namespace App\Services\Domain;

use App\Actions\Domain\CreateDomainAction;
use App\Actions\Domain\DeleteDomainAction;
use App\Actions\Domain\FindDomainByDomainAction;
use App\Actions\Domain\FindDomainByIdAction;
use App\Actions\Domain\PaginateDomainsAction;
use App\Actions\Domain\UpdateDomainAction;
use App\DTOs\Domain\CreateDomainData;
use App\DTOs\Domain\DomainFiltersData;
use App\DTOs\Domain\UpdateDomainData;
use App\Models\Domain;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Orchestrate domain operations for controllers, API and Filament.
 */
final class DomainService
{
    /**
     * @param CreateDomainAction         $createDomainAction         Create domain action.
     * @param UpdateDomainAction         $updateDomainAction         Update domain action.
     * @param DeleteDomainAction         $deleteDomainAction         Delete domain action.
     * @param FindDomainByIdAction       $findDomainByIdAction       Find domain by ID action.
     * @param FindDomainByDomainAction   $findDomainByDomainAction   Find domain by hostname action.
     * @param PaginateDomainsAction      $paginateDomainsAction      Paginate domains action.
     */
    public function __construct(
        private readonly CreateDomainAction $createDomainAction,
        private readonly UpdateDomainAction $updateDomainAction,
        private readonly DeleteDomainAction $deleteDomainAction,
        private readonly FindDomainByIdAction $findDomainByIdAction,
        private readonly FindDomainByDomainAction $findDomainByDomainAction,
        private readonly PaginateDomainsAction $paginateDomainsAction,
    ) {}

    /**
     * Create and persist a new domain.
     *
     * @param CreateDomainData $data Validated domain creation data.
     *
     * @return Domain The created domain.
     */
    public function create(CreateDomainData $data): Domain
    {
        return $this->createDomainAction->execute($data);
    }

    /**
     * Update and persist changes to the given domain.
     *
     * @param Domain           $domain The domain to update.
     * @param UpdateDomainData $data   Validated domain update data.
     *
     * @return Domain The updated domain.
     */
    public function update(Domain $domain, UpdateDomainData $data): Domain
    {
        return $this->updateDomainAction->execute($domain, $data);
    }

    /**
     * Delete the given domain.
     *
     * @param Domain $domain The domain to delete.
     *
     * @return bool Whether the domain was deleted.
     */
    public function delete(Domain $domain): bool
    {
        return $this->deleteDomainAction->execute($domain);
    }

    /**
     * Find a domain by its identifier.
     *
     * @param string $id Domain identifier.
     *
     * @return Domain|null The matching domain, if found.
     */
    public function findById(string $id): ?Domain
    {
        return $this->findDomainByIdAction->execute($id);
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
        return $this->findDomainByDomainAction->execute($domain);
    }

    /**
     * Retrieve a paginated list of domains for the given filters.
     *
     * @param DomainFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated domain results.
     */
    public function paginate(DomainFiltersData $filters): LengthAwarePaginator
    {
        return $this->paginateDomainsAction->execute($filters);
    }
}
