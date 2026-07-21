<?php

declare(strict_types=1);

namespace App\Actions\Attachment;

use App\DTOs\Attachment\CreateAttachmentData;
use App\Models\Attachment;
use App\Repositories\Contracts\AttachmentRepositoryInterface;

/**
 * Create and persist a new attachment from validated input data.
 */
final class CreateAttachmentAction
{
    /**
     * @param AttachmentRepositoryInterface $attachmentRepository Attachment persistence contract.
     */
    public function __construct(
        private readonly AttachmentRepositoryInterface $attachmentRepository,
    ) {}

    /**
     * Create and persist a new attachment.
     *
     * @param CreateAttachmentData $data Validated attachment creation data.
     *
     * @return Attachment The created attachment.
     */
    public function execute(CreateAttachmentData $data): Attachment
    {
        return $this->attachmentRepository->create($data);
    }
}
