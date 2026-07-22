<?php

declare(strict_types=1);

namespace App\Actions\Domain;

use App\DTOs\Domain\CreateDomainData;
use App\Models\Domain;
use App\Repositories\Contracts\DomainRepositoryInterface;

/**
 * Create and persist a new domain from validated input data.
 */
final class CreateDomainAction
{
    /**
     * @param DomainRepositoryInterface $domainRepository Domain persistence contract.
     */
    public function __construct(
        private readonly DomainRepositoryInterface $domainRepository,
    ) {}

    /**
     * Create and persist a new domain.
     *
     * @param CreateDomainData $data Validated domain creation data.
     *
     * @return Domain The created domain.
     */
    public function execute(CreateDomainData $data): Domain
    {
        return $this->domainRepository->create($data);
    }
}
