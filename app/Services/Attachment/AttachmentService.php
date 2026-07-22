<?php

declare(strict_types=1);

namespace App\Services\Attachment;

use App\Actions\Attachment\CreateAttachmentAction;
use App\Actions\Attachment\DeleteAttachmentAction;
use App\Actions\Attachment\FindAttachmentByChecksumAction;
use App\Actions\Attachment\FindAttachmentByIdAction;
use App\Actions\Attachment\PaginateAttachmentsAction;
use App\Actions\Attachment\UpdateAttachmentAction;
use App\DTOs\Attachment\AttachmentFiltersData;
use App\DTOs\Attachment\CreateAttachmentData;
use App\DTOs\Attachment\UpdateAttachmentData;
use App\Models\Attachment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Orchestrate attachment operations for controllers, API and Filament.
 */
final class AttachmentService
{
    /**
     * @param CreateAttachmentAction           $createAttachmentAction           Create attachment action.
     * @param UpdateAttachmentAction           $updateAttachmentAction           Update attachment action.
     * @param DeleteAttachmentAction           $deleteAttachmentAction           Delete attachment action.
     * @param FindAttachmentByIdAction         $findAttachmentByIdAction         Find attachment by ID action.
     * @param FindAttachmentByChecksumAction   $findAttachmentByChecksumAction   Find attachment by checksum action.
     * @param PaginateAttachmentsAction        $paginateAttachmentsAction        Paginate attachments action.
     */
    public function __construct(
        private readonly CreateAttachmentAction $createAttachmentAction,
        private readonly UpdateAttachmentAction $updateAttachmentAction,
        private readonly DeleteAttachmentAction $deleteAttachmentAction,
        private readonly FindAttachmentByIdAction $findAttachmentByIdAction,
        private readonly FindAttachmentByChecksumAction $findAttachmentByChecksumAction,
        private readonly PaginateAttachmentsAction $paginateAttachmentsAction,
    ) {}

    /**
     * Create and persist a new attachment.
     *
     * @param CreateAttachmentData $data Validated attachment creation data.
     *
     * @return Attachment The created attachment.
     */
    public function create(CreateAttachmentData $data): Attachment
    {
        return $this->createAttachmentAction->execute($data);
    }

    /**
     * Update and persist changes to the given attachment.
     *
     * @param Attachment           $attachment The attachment to update.
     * @param UpdateAttachmentData $data       Validated attachment update data.
     *
     * @return Attachment The updated attachment.
     */
    public function update(Attachment $attachment, UpdateAttachmentData $data): Attachment
    {
        return $this->updateAttachmentAction->execute($attachment, $data);
    }

    /**
     * Delete the given attachment.
     *
     * @param Attachment $attachment The attachment to delete.
     *
     * @return bool Whether the attachment was deleted.
     */
    public function delete(Attachment $attachment): bool
    {
        return $this->deleteAttachmentAction->execute($attachment);
    }

    /**
     * Find an attachment by its identifier.
     *
     * @param string $id Attachment identifier.
     *
     * @return Attachment|null The matching attachment, if found.
     */
    public function findById(string $id): ?Attachment
    {
        return $this->findAttachmentByIdAction->execute($id);
    }

    /**
     * Find an attachment by its SHA-256 checksum.
     *
     * @param string $checksum SHA-256 checksum of the file content.
     *
     * @return Attachment|null The matching attachment, if found.
     */
    public function findByChecksum(string $checksum): ?Attachment
    {
        return $this->findAttachmentByChecksumAction->execute($checksum);
    }

    /**
     * Retrieve a paginated list of attachments for the given filters.
     *
     * @param AttachmentFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated attachment results.
     */
    public function paginate(AttachmentFiltersData $filters): LengthAwarePaginator
    {
        return $this->paginateAttachmentsAction->execute($filters);
    }
}
