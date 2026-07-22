<?php

declare(strict_types=1);

namespace App\Actions\Domain;

use App\Models\Domain;
use App\Repositories\Contracts\DomainRepositoryInterface;

/**
 * Delete an existing domain.
 */
final class DeleteDomainAction
{
    /**
     * @param DomainRepositoryInterface $domainRepository Domain persistence contract.
     */
    public function __construct(
        private readonly DomainRepositoryInterface $domainRepository,
    ) {}

    /**
     * Delete the given domain.
     *
     * @param Domain $domain The domain to delete.
     *
     * @return bool Whether the domain was deleted.
     */
    public function execute(Domain $domain): bool
    {
        return $this->domainRepository->delete($domain);
    }
}
