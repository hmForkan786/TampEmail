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
 *
 * Common CRUD operations are inherited from BaseEloquentRepository.
 *
 * @extends BaseEloquentRepository<Inbox, CreateInboxData, UpdateInboxData>
 */
final class EloquentInboxRepository extends BaseEloquentRepository implements InboxRepositoryInterface
{
    /**
     * @return Inbox
     */
    protected function model(): Inbox
    {
        return new Inbox;
    }

    /**
     * Find an inbox by its full email address.
     */
    public function findByAddress(string $fullAddress): ?Inbox
    {
        return $this->model()->newQuery()
            ->where('full_address', $fullAddress)
            ->first();
    }

    /**
     * Count the non-deleted inboxes owned by the given user.
     *
     * Soft-deleted rows are excluded by Eloquent's SoftDeletes global scope.
     * Active and expiration state are intentionally ignored.
     *
     * @param string $userId Owning user UUID.
     *
     * @return int Number of inboxes owned by the user.
     */
    public function countForUser(string $userId): int
    {
        return $this->model()->newQuery()
            ->where('user_id', $userId)
            ->count();
    }

    /**
     * Retrieve a paginated list of inboxes matching the given filters.
     */
    public function paginate(InboxFiltersData $filters): LengthAwarePaginator
    {
        $query = $this->model()->newQuery();

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

        if ($filters->isExpired === false) {
            $query->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
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
