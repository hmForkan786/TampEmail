<?php

declare(strict_types=1);

namespace App\Actions\Attachment;

use App\Models\Attachment;
use App\Repositories\Contracts\AttachmentRepositoryInterface;

/**
 * Find an existing attachment by its SHA-256 checksum.
 */
final class FindAttachmentByChecksumAction
{
    /**
     * @param AttachmentRepositoryInterface $attachmentRepository Attachment persistence contract.
     */
    public function __construct(
        private readonly AttachmentRepositoryInterface $attachmentRepository,
    ) {}

    /**
     * Find the attachment for the given SHA-256 checksum.
     *
     * @param string $checksum SHA-256 checksum of the file content.
     *
     * @return Attachment|null The matching attachment, if found.
     */
    public function execute(string $checksum): ?Attachment
    {
        return $this->attachmentRepository->findByChecksum($checksum);
    }
}
