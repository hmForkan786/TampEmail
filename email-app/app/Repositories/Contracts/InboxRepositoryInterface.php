<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Inbox\CreateInboxData;
use App\DTOs\Inbox\InboxFiltersData;
use App\DTOs\Inbox\UpdateInboxData;
use App\Models\Inbox;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Contract for inbox persistence operations.
 *
 * Common CRUD methods are inherited from BaseRepositoryInterface.
 * Module-specific lookups and Filter DTO pagination remain here.
 *
 * @extends BaseRepositoryInterface<Inbox, CreateInboxData, UpdateInboxData>
 */
interface InboxRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find an inbox by its full email address.
     */
    public function findByAddress(string $fullAddress): ?Inbox;

    /**
     * Retrieve a paginated list of inboxes matching the given filters.
     */
    public function paginate(InboxFiltersData $filters): LengthAwarePaginator;
}
