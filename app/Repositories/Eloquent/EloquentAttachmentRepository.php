<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DTOs\Attachment\AttachmentFiltersData;
use App\DTOs\Attachment\CreateAttachmentData;
use App\DTOs\Attachment\UpdateAttachmentData;
use App\Models\Attachment;
use App\Repositories\Contracts\AttachmentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent-backed persistence and query implementation for attachments.
 *
 * Common CRUD operations are inherited from BaseEloquentRepository.
 *
 * @extends BaseEloquentRepository<Attachment, CreateAttachmentData, UpdateAttachmentData>
 */
final class EloquentAttachmentRepository extends BaseEloquentRepository implements AttachmentRepositoryInterface
{
    /**
     * @return Attachment
     */
    protected function model(): Attachment
    {
        return new Attachment;
    }

    /**
     * Find an attachment by its SHA-256 checksum.
     *
     * @param string $checksumSha256 SHA-256 checksum of the file content.
     *
     * @return Attachment|null The matching attachment, if found.
     */
    public function findByChecksum(string $checksumSha256): ?Attachment
    {
        return $this->model()->newQuery()
            ->where('checksum_sha256', $checksumSha256)
            ->first();
    }

    /**
     * Retrieve a paginated list of attachments matching the given filters.
     *
     * @param AttachmentFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated attachment results.
     */
    public function paginate(AttachmentFiltersData $filters): LengthAwarePaginator
    {
        $query = $this->model()->newQuery();

        if ($filters->emailId !== null) {
            $query->where('email_id', $filters->emailId);
        }

        if ($filters->scanStatus !== null) {
            $query->where('scan_status', $filters->scanStatus);
        }

        if ($filters->isSafe === true) {
            $query->where('is_safe', true);
        }

        if ($filters->isSafe === false) {
            $query->where('is_safe', false);
        }

        if ($filters->mimeType !== null) {
            $query->where('mime_type', $filters->mimeType);
        }

        if ($filters->extension !== null) {
            $query->where('extension', $filters->extension);
        }

        if ($filters->hasSearch()) {
            $search = $filters->search;

            $query->where(function ($query) use ($search): void {
                $query->where('original_filename', 'like', "%{$search}%")
                    ->orWhere('stored_filename', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderBy($filters->sortBy, $filters->sortDirection)
            ->paginate($filters->perPage);
    }
}
