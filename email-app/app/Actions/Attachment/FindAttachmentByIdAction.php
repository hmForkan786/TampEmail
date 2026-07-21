<?php

declare(strict_types=1);

namespace App\Actions\Attachment;

use App\Models\Attachment;
use App\Repositories\Contracts\AttachmentRepositoryInterface;

/**
 * Find an existing attachment by its identifier.
 */
final class FindAttachmentByIdAction
{
    /**
     * @param AttachmentRepositoryInterface $attachmentRepository Attachment persistence contract.
     */
    public function __construct(
        private readonly AttachmentRepositoryInterface $attachmentRepository,
    ) {}

    /**
     * Find the attachment for the given identifier.
     *
     * @param string $id Attachment identifier.
     *
     * @return Attachment|null The matching attachment, if found.
     */
    public function execute(string $id): ?Attachment
    {
        return $this->attachmentRepository->findById($id);
    }
}
