<?php

declare(strict_types=1);

namespace App\Actions\Domain;

use App\DTOs\Domain\UpdateDomainData;
use App\Models\Domain;
use App\Repositories\Contracts\DomainRepositoryInterface;

/**
 * Update an existing domain from partial input data.
 */
final class UpdateDomainAction
{
    /**
     * @param DomainRepositoryInterface $domainRepository Domain persistence contract.
     */
    public function __construct(
        private readonly DomainRepositoryInterface $domainRepository,
    ) {}

    /**
     * Update and persist changes to the given domain.
     *
     * @param Domain           $domain The domain to update.
     * @param UpdateDomainData $data   Validated domain update data.
     *
     * @return Domain The updated domain.
     */
    public function execute(Domain $domain, UpdateDomainData $data): Domain
    {
        return $this->domainRepository->update($domain, $data);
    }
}
