<?php

declare(strict_types=1);

namespace App\Actions\Domain;

use App\Models\Domain;
use App\Repositories\Contracts\DomainRepositoryInterface;

/**
 * Find an existing domain by its unique domain hostname.
 */
final class FindDomainByDomainAction
{
    /**
     * @param DomainRepositoryInterface $domainRepository Domain persistence contract.
     */
    public function __construct(
        private readonly DomainRepositoryInterface $domainRepository,
    ) {}

    /**
     * Find the domain for the given unique domain hostname.
     *
     * @param string $domain Unique domain hostname.
     *
     * @return Domain|null The matching domain, if found.
     */
    public function execute(string $domain): ?Domain
    {
        return $this->domainRepository->findByDomain($domain);
    }
}
