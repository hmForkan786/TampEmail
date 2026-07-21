<?php

declare(strict_types=1);

namespace App\Actions\Attachment;

use App\DTOs\Attachment\AttachmentFiltersData;
use App\Repositories\Contracts\AttachmentRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Retrieve a paginated list of attachments matching the given filters.
 */
final class PaginateAttachmentsAction
{
    /**
     * @param AttachmentRepositoryInterface $attachmentRepository Attachment persistence contract.
     */
    public function __construct(
        private readonly AttachmentRepositoryInterface $attachmentRepository,
    ) {}

    /**
     * Retrieve a paginated list of attachments for the given filters.
     *
     * @param AttachmentFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated attachment results.
     */
    public function execute(AttachmentFiltersData $filters): LengthAwarePaginator
    {
        return $this->attachmentRepository->paginate($filters);
    }
}
