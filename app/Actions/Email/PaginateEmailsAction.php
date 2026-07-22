<?php

declare(strict_types=1);

namespace App\Actions\Email;

use App\DTOs\Email\EmailFiltersData;
use App\Repositories\Contracts\EmailRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Retrieve a paginated list of emails matching the given filters.
 */
final class PaginateEmailsAction
{
    /**
     * @param EmailRepositoryInterface $emailRepository Email persistence contract.
     */
    public function __construct(
        private readonly EmailRepositoryInterface $emailRepository,
    ) {}

    /**
     * Retrieve a paginated list of emails for the given filters.
     *
     * @param EmailFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated email results.
     */
    public function execute(EmailFiltersData $filters): LengthAwarePaginator
    {
        return $this->emailRepository->paginate($filters);
    }
}
