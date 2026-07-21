<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Email\CreateEmailData;
use App\DTOs\Email\EmailFiltersData;
use App\DTOs\Email\UpdateEmailData;
use App\Models\Email;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Contract for email persistence operations.
 *
 * Common CRUD methods are inherited from BaseRepositoryInterface.
 * Module-specific lookups and Filter DTO pagination remain here.
 *
 * @extends BaseRepositoryInterface<Email, CreateEmailData, UpdateEmailData>
 */
interface EmailRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find an email by its unique message identifier.
     *
     * @param string $messageId Unique external message identifier.
     *
     * @return Email|null The matching email, if found.
     */
    public function findByMessageId(string $messageId): ?Email;

    /**
     * Retrieve a paginated list of emails matching the given filters.
     *
     * @param EmailFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated email results.
     */
    public function paginate(EmailFiltersData $filters): LengthAwarePaginator;
}
