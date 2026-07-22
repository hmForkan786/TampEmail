<?php

declare(strict_types=1);

namespace App\Actions\Domain;

use App\Models\Domain;
use App\Repositories\Contracts\DomainRepositoryInterface;

/**
 * Find an existing domain by its identifier.
 */
final class FindDomainByIdAction
{
    /**
     * @param DomainRepositoryInterface $domainRepository Domain persistence contract.
     */
    public function __construct(
        private readonly DomainRepositoryInterface $domainRepository,
    ) {}

    /**
     * Find the domain for the given identifier.
     *
     * @param string $id Domain identifier.
     *
     * @return Domain|null The matching domain, if found.
     */
    public function execute(string $id): ?Domain
    {
        return $this->domainRepository->findById($id);
    }
}
