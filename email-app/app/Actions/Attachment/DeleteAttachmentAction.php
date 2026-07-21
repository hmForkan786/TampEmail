<?php

declare(strict_types=1);

namespace App\Actions\Attachment;

use App\Models\Attachment;
use App\Repositories\Contracts\AttachmentRepositoryInterface;

/**
 * Delete an existing attachment.
 */
final class DeleteAttachmentAction
{
    /**
     * @param AttachmentRepositoryInterface $attachmentRepository Attachment persistence contract.
     */
    public function __construct(
        private readonly AttachmentRepositoryInterface $attachmentRepository,
    ) {}

    /**
     * Delete the given attachment.
     *
     * @param Attachment $attachment The attachment to delete.
     *
     * @return bool Whether the attachment was deleted.
     */
    public function execute(Attachment $attachment): bool
    {
        return $this->attachmentRepository->delete($attachment);
    }
}
