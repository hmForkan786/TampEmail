<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Attachment\AttachmentFiltersData;
use App\DTOs\Attachment\CreateAttachmentData;
use App\DTOs\Attachment\UpdateAttachmentData;
use App\Models\Attachment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Contract for attachment persistence operations.
 *
 * Common CRUD methods are inherited from BaseRepositoryInterface.
 * Module-specific lookups and Filter DTO pagination remain here.
 *
 * @extends BaseRepositoryInterface<Attachment, CreateAttachmentData, UpdateAttachmentData>
 */
interface AttachmentRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find an attachment by its SHA-256 checksum.
     *
     * @param string $checksumSha256 SHA-256 checksum of the file content.
     *
     * @return Attachment|null The matching attachment, if found.
     */
    public function findByChecksum(string $checksumSha256): ?Attachment;

    /**
     * Retrieve a paginated list of attachments matching the given filters.
     *
     * @param AttachmentFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated attachment results.
     */
    public function paginate(AttachmentFiltersData $filters): LengthAwarePaginator;
}
