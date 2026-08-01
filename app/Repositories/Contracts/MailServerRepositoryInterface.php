<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\MailServer\CreateMailServerData;
use App\DTOs\MailServer\MailServerFiltersData;
use App\DTOs\MailServer\UpdateMailServerData;
use App\Models\MailServer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Contract for mail server persistence operations.
 *
 * Common CRUD methods are inherited from BaseRepositoryInterface.
 * Module-specific lookups and Filter DTO pagination remain here.
 *
 * @extends BaseRepositoryInterface<
 *     MailServer,
 *     CreateMailServerData,
 *     UpdateMailServerData
 * >
 */
interface MailServerRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find a mail server by its unique hostname.
     *
     * @param  string  $hostname  Unique mail server hostname.
     * @return MailServer|null The matching mail server, if found.
     */
    public function findByHostname(string $hostname): ?MailServer;

    /**
     * Retrieve a paginated list of mail servers matching the given filters.
     *
     * @param  MailServerFiltersData  $filters  Pagination and filter criteria.
     * @return LengthAwarePaginator Paginated mail server results.
     */
    public function paginate(MailServerFiltersData $filters): LengthAwarePaginator;

    /**
     * Select and lock the best available mail server for the given pools.
     *
     * Deterministic HA routing: active status, fresh health, min score,
     * under capacity → lowest utilization → health → priority → id.
     * Must run inside a transaction. No entitlement resolution.
     *
     * @param  array<string>  $poolKeys
     */
    public function selectAvailableForPoolsForUpdate(array $poolKeys): ?MailServer;
}
