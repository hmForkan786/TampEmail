<?php

declare(strict_types=1);

namespace App\Actions\MailServer;

use App\DTOs\MailServer\MailServerFiltersData;
use App\Repositories\Contracts\MailServerRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Retrieve a paginated list of mail servers matching the given filters.
 */
final class PaginateMailServersAction
{
    /**
     * @param MailServerRepositoryInterface $mailServerRepository Mail server persistence contract.
     */
    public function __construct(
        private readonly MailServerRepositoryInterface $mailServerRepository,
    ) {}

    /**
     * Retrieve a paginated list of mail servers for the given filters.
     *
     * @param MailServerFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated mail server results.
     */
    public function execute(MailServerFiltersData $filters): LengthAwarePaginator
    {
        return $this->mailServerRepository->paginate($filters);
    }
}
