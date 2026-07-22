<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DTOs\Email\CreateEmailData;
use App\DTOs\Email\EmailFiltersData;
use App\DTOs\Email\UpdateEmailData;
use App\Models\Email;
use App\Repositories\Contracts\EmailRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent-backed persistence and query implementation for emails.
 *
 * Common CRUD operations are inherited from BaseEloquentRepository.
 *
 * @extends BaseEloquentRepository<Email, CreateEmailData, UpdateEmailData>
 */
final class EloquentEmailRepository extends BaseEloquentRepository implements EmailRepositoryInterface
{
    /**
     * @return Email
     */
    protected function model(): Email
    {
        return new Email;
    }

    /**
     * Find an email by its unique message identifier.
     *
     * @param string $messageId Unique external message identifier.
     *
     * @return Email|null The matching email, if found.
     */
    public function findByMessageId(string $messageId): ?Email
    {
        return $this->model()->newQuery()
            ->where('message_id', $messageId)
            ->first();
    }

    /**
     * Retrieve a paginated list of emails matching the given filters.
     *
     * @param EmailFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated email results.
     */
    public function paginate(EmailFiltersData $filters): LengthAwarePaginator
    {
        $query = $this->model()->newQuery();

        if ($filters->inboxId !== null) {
            $query->where('inbox_id', $filters->inboxId);
        }

        if ($filters->processingStatus !== null) {
            $query->where('processing_status', $filters->processingStatus);
        }

        if ($filters->senderEmail !== null) {
            $query->where('sender_email', $filters->senderEmail);
        }

        if ($filters->recipientEmail !== null) {
            $query->where('recipient_email', $filters->recipientEmail);
        }

        if ($filters->hasAttachments === true) {
            $query->where('has_attachments', true);
        }

        if ($filters->hasAttachments === false) {
            $query->where('has_attachments', false);
        }

        if ($filters->receivedFrom !== null) {
            $query->where('received_at', '>=', $filters->receivedFrom);
        }

        if ($filters->receivedTo !== null) {
            $query->where('received_at', '<=', $filters->receivedTo);
        }

        if ($filters->hasSearch()) {
            $search = $filters->search;

            $query->where(function ($query) use ($search): void {
                $query->where('subject', 'like', "%{$search}%")
                    ->orWhere('sender_email', 'like', "%{$search}%")
                    ->orWhere('recipient_email', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderBy($filters->sortBy, $filters->sortDirection)
            ->paginate($filters->perPage);
    }
}
