<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DTOs\MailServer\CreateMailServerData;
use App\DTOs\MailServer\MailServerFiltersData;
use App\DTOs\MailServer\UpdateMailServerData;
use App\Models\MailServer;
use App\Repositories\Contracts\MailServerRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent-backed persistence and query implementation for mail servers.
 *
 * Common CRUD operations are inherited from BaseEloquentRepository.
 *
 * @extends BaseEloquentRepository<MailServer, CreateMailServerData, UpdateMailServerData>
 */
final class EloquentMailServerRepository extends BaseEloquentRepository implements MailServerRepositoryInterface
{
    /**
     * @return MailServer
     */
    protected function model(): MailServer
    {
        return new MailServer;
    }

    /**
     * Find a mail server by its unique hostname.
     *
     * @param string $hostname Unique mail server hostname.
     *
     * @return MailServer|null The matching mail server, if found.
     */
    public function findByHostname(string $hostname): ?MailServer
    {
        return $this->model()->newQuery()
            ->where('hostname', $hostname)
            ->first();
    }

    /**
     * Retrieve a paginated list of mail servers matching the given filters.
     *
     * @param MailServerFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated mail server results.
     */
    public function paginate(MailServerFiltersData $filters): LengthAwarePaginator
    {
        $query = $this->model()->newQuery();

        if ($filters->provider !== null) {
            $query->where('provider', $filters->provider);
        }

        if ($filters->protocol !== null) {
            $query->where('protocol', $filters->protocol);
        }

        if ($filters->isActive !== null) {
            $query->where('is_active', $filters->isActive);
        }

        if ($filters->hasSearch()) {
            $search = $filters->search;

            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('hostname', 'like', "%{$search}%");
            });
        }

        if ($filters->hasSorting()) {
            $query->orderBy($filters->sortBy, $filters->sortDirection);
        }

        return $query->paginate($filters->perPage);
    }
}
