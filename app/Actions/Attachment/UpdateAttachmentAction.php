<?php

declare(strict_types=1);

namespace App\Actions\Attachment;

use App\DTOs\Attachment\UpdateAttachmentData;
use App\Models\Attachment;
use App\Repositories\Contracts\AttachmentRepositoryInterface;

/**
 * Update an existing attachment from partial input data.
 */
final class UpdateAttachmentAction
{
    /**
     * @param AttachmentRepositoryInterface $attachmentRepository Attachment persistence contract.
     */
    public function __construct(
        private readonly AttachmentRepositoryInterface $attachmentRepository,
    ) {}

    /**
     * Update and persist changes to the given attachment.
     *
     * @param Attachment           $attachment The attachment to update.
     * @param UpdateAttachmentData $data       Validated attachment update data.
     *
     * @return Attachment The updated attachment.
     */
    public function execute(Attachment $attachment, UpdateAttachmentData $data): Attachment
    {
        return $this->attachmentRepository->update($attachment, $data);
    }
}
