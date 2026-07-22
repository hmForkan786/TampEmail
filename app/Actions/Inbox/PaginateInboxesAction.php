<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\DTOs\Inbox\InboxFiltersData;
use App\Repositories\Contracts\InboxRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Retrieve a paginated list of inboxes matching the given filters.
 */
final class PaginateInboxesAction
{
    /**
     * @param InboxRepositoryInterface $inboxRepository Inbox persistence contract.
     */
    public function __construct(
        private readonly InboxRepositoryInterface $inboxRepository,
    ) {}

    /**
     * Retrieve a paginated list of inboxes for the given filters.
     *
     * @param InboxFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated inbox results.
     */
    public function execute(InboxFiltersData $filters): LengthAwarePaginator
    {
        return $this->inboxRepository->paginate($filters);
    }
}
