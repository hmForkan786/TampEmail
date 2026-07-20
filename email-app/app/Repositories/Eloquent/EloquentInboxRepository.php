<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DTOs\Inbox\CreateInboxData;
use App\DTOs\Inbox\InboxFiltersData;
use App\DTOs\Inbox\UpdateInboxData;
use App\Models\Inbox;
use App\Repositories\Contracts\InboxRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent-backed persistence and query implementation for inboxes.
 */
final class EloquentInboxRepository implements InboxRepositoryInterface
{
    /**
     * Persist a new inbox.
     */
    public function create(CreateInboxData $data): Inbox
    {
        return Inbox::create($data->toArray());
    }

    /**
     * Update an existing inbox with partial data.
     */
    public function update(Inbox $inbox, UpdateInboxData $data): Inbox
    {
        $inbox->update($data->toArray());

        return $inbox->refresh();
    }

    /**
     * Delete the given inbox.
     */
    public function delete(Inbox $inbox): bool
    {
        return (bool) $inbox->delete();
    }

    /**
     * Find an inbox by its UUID.
     */
    public function findById(string $id): ?Inbox
    {
        return Inbox::find($id);
    }

    /**
     * Find an inbox by its full email address.
     */
    public function findByAddress(string $fullAddress): ?Inbox
    {
        return Inbox::query()
            ->where('full_address', $fullAddress)
            ->first();
    }

    /**
     * Retrieve a paginated list of inboxes matching the given filters.
     */
    public function paginate(InboxFiltersData $filters): LengthAwarePaginator
    {
        $query = Inbox::query();

        if ($filters->userId !== null) {
            $query->where('user_id', $filters->userId);
        }

        if ($filters->domainId !== null) {
            $query->where('domain_id', $filters->domainId);
        }

        if ($filters->inboxType !== null) {
            $query->where('inbox_type', $filters->inboxType);
        }

        if ($filters->isActive === true) {
            $query->active();
        }

        if ($filters->isActive === false) {
            $query->where('is_active', false);
        }

        if ($filters->isExpired === true) {
            $query->expired();
        }

        if ($filters->hasSearch()) {
            $search = $filters->search;

            $query->where(function ($query) use ($search): void {
                $query->where('local_part', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%")
                    ->orWhere('full_address', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderBy($filters->sortBy ?? 'created_at', $filters->sortDirection ?? 'desc')
            ->paginate($filters->perPage ?? 15);
    }
}
