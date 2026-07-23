<?php

declare(strict_types=1);

namespace App\Services\Inbox;

use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Models\Email;
use App\Models\Inbox;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class OwnedInboxVisibilityService
{
    public function queryForOwner(User $owner, array $filters = [])
    {
        $query = Inbox::query()->ownedBy((string) $owner->getKey())
            ->withCount(['emails as unread_count' => fn ($q) => $q->where('is_read', false)])
            ->selectSub(Attachment::query()->selectRaw('count(*)')
                ->where('is_safe', true)->where('scan_status', AttachmentScanStatus::Clean)
                ->whereHas('email', fn ($q) => $q->whereColumn('emails.inbox_id', 'inboxes.id')), 'safe_attachment_count');
        $query->select('inboxes.*')->selectSub(Email::query()->selectRaw('count(*)')->whereColumn('emails.inbox_id', 'inboxes.id'), 'email_count');
        if (array_key_exists('is_active', $filters)) $query->where('is_active', (bool) $filters['is_active']);
        if (array_key_exists('expired', $filters)) {
            $query->when($filters['expired'], fn ($q) => $q->whereNotNull('expires_at')->where('expires_at', '<=', now()), fn ($q) => $q->where(fn ($x) => $x->whereNull('expires_at')->orWhere('expires_at', '>', now())));
        }
        if (filled($filters['domain_id'] ?? null)) $query->where('domain_id', $filters['domain_id']);
        if (array_key_exists('has_unread', $filters)) {
            $filters['has_unread']
                ? $query->whereHas('emails', fn ($q) => $q->where('is_read', false))
                : $query->whereDoesntHave('emails', fn ($q) => $q->where('is_read', false));
        }
        if (filled($filters['created_after'] ?? null)) $query->where('created_at', '>=', $filters['created_after']);
        if (filled($filters['created_before'] ?? null)) $query->where('created_at', '<=', $filters['created_before']);
        return $query;
    }

    public function paginateForOwner(User $owner, array $filters = []): LengthAwarePaginator
    {
        $sort = $filters['sort'] ?? 'created_at';
        return $this->queryForOwner($owner, $filters)->orderBy($sort, $filters['direction'] ?? 'desc')->paginate((int) ($filters['per_page'] ?? 15));
    }
}
