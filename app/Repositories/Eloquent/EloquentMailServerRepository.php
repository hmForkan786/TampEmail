<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DTOs\MailServer\CreateMailServerData;
use App\DTOs\MailServer\MailServerFiltersData;
use App\DTOs\MailServer\UpdateMailServerData;
use App\Models\MailServer;
use App\Repositories\Contracts\MailServerRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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

    /**
     * Select and lock the best available mail server for the given pools.
     *
     * Filters to active, healthy servers whose pool_key is in the given list,
     * ordered deterministically by priority. Applies a row-level lock, so it
     * must be called within an open transaction. Capacity is verified with a
     * locking current-read after the server row is locked so concurrent
     * creators cannot oversubscribe under MySQL REPEATABLE READ.
     * Performs no entitlement resolution.
     *
     * @param  array<string>  $poolKeys  Allowed pool keys.
     * @return MailServer|null The locked selected mail server, if any.
     */
    public function selectAvailableForPoolsForUpdate(array $poolKeys): ?MailServer
    {
        if ($poolKeys === []) {
            return null;
        }

        $servers = $this->model()->newQuery()
            ->whereIn('pool_key', $poolKeys)
            ->where('is_active', true)
            ->whereNotNull('last_health_check_at')
            ->where('last_health_check_at', '>=', now()->subMinutes(10))
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        foreach ($servers as $server) {
            if ($server->max_inboxes === null) {
                return $server;
            }

            $utilization = (int) DB::table('inboxes')
                ->where('mail_server_id', $server->id)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->lockForUpdate()
                ->count();

            if ($utilization < (int) $server->max_inboxes) {
                return $server;
            }
        }

        return null;
    }
}
