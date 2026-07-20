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
 */
interface InboxRepositoryInterface
{
    /**
     * Persist a new inbox.
     */
    public function create(CreateInboxData $data): Inbox;

    /**
     * Update an existing inbox with partial data.
     */
    public function update(Inbox $inbox, UpdateInboxData $data): Inbox;

    /**
     * Delete the given inbox.
     */
    public function delete(Inbox $inbox): bool;

    /**
     * Find an inbox by its UUID.
     */
    public function findById(string $id): ?Inbox;

    /**
     * Find an inbox by its full email address.
     */
    public function findByAddress(string $fullAddress): ?Inbox;

    /**
     * Retrieve a paginated list of inboxes matching the given filters.
     */
    public function paginate(InboxFiltersData $filters): LengthAwarePaginator;
}
