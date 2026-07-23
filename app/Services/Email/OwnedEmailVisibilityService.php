<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\Email;
use App\Models\Inbox;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Resolve owner-visible inboxes and emails without bypassing ownership scopes.
 */
final class OwnedEmailVisibilityService
{
    /**
     * Resolve an inbox owned by the actor that is still visible for read APIs.
     *
     * @throws ModelNotFoundException
     */
    public function resolveOwnedInbox(User $owner, string $inboxId): Inbox
    {
        return Inbox::query()
            ->ownedBy((string) $owner->getKey())
            ->visibleToOwner()
            ->whereKey($inboxId)
            ->firstOrFail();
    }

    /**
     * Paginate non-deleted emails for a previously resolved owned inbox.
     *
     * @return LengthAwarePaginator<int, Email>
     */
    public function paginateForInbox(Inbox $inbox, array $filters = []): LengthAwarePaginator
    {
        $query = Email::query()->where('inbox_id', $inbox->getKey());
        if (array_key_exists('is_read', $filters)) $query->where('is_read', (bool) $filters['is_read']);
        if (filled($filters['from'] ?? null)) $query->whereRaw('LOWER(sender_email) LIKE ?', ['%'.mb_strtolower(trim((string) $filters['from'])).'%']);
        if (filled($filters['to'] ?? null)) $query->whereRaw('LOWER(recipient_email) LIKE ?', ['%'.mb_strtolower(trim((string) $filters['to'])).'%']);
        if (filled($filters['subject'] ?? null)) $query->whereRaw('LOWER(subject) LIKE ?', ['%'.mb_strtolower(trim((string) $filters['subject'])).'%']);
        if (filled($filters['message_id'] ?? null)) $query->where('message_id', (string) $filters['message_id']);
        if (filled($filters['received_after'] ?? null)) $query->where('received_at', '>=', $filters['received_after']);
        if (filled($filters['received_before'] ?? null)) $query->where('received_at', '<=', $filters['received_before']);
        if (array_key_exists('has_attachments', $filters)) $query->where('has_attachments', (bool) $filters['has_attachments']);

        $sort = $filters['sort'] ?? 'received_at';
        $direction = $filters['direction'] ?? 'desc';
        return $query
            ->with(['body', 'attachments', 'inbox'])
            ->orderBy($sort, $direction)
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Find a non-deleted email that belongs to a previously resolved owned inbox.
     *
     * @throws ModelNotFoundException
     */
    public function findForInbox(Inbox $inbox, string $emailId): Email
    {
        return Email::query()
            ->where('inbox_id', $inbox->getKey())
            ->with(['body', 'attachments', 'inbox'])
            ->whereKey($emailId)
            ->firstOrFail();
    }
}
