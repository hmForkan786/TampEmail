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
    public function paginateForInbox(Inbox $inbox, int $perPage = 15): LengthAwarePaginator
    {
        return Email::query()
            ->where('inbox_id', $inbox->getKey())
            ->with(['body', 'attachments', 'inbox'])
            ->orderByDesc('received_at')
            ->paginate(max(1, min($perPage, 100)));
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
