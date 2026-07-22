<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Email;
use App\Models\Inbox;
use App\Models\User;

/**
 * Owner-scoped email visibility. Platform roles do not bypass inbox ownership.
 */
final class EmailPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive() && ! $user->trashed();
    }

    public function view(User $user, Email $email): bool
    {
        if (! $user->isActive() || $user->trashed()) {
            return false;
        }

        $inbox = $email->relationLoaded('inbox')
            ? $email->inbox
            : $email->inbox()->first();

        return $this->ownsVisibleInbox($user, $inbox);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Email $email): bool
    {
        return false;
    }

    public function delete(User $user, Email $email): bool
    {
        return false;
    }

    public function restore(User $user, Email $email): bool
    {
        return false;
    }

    public function forceDelete(User $user, Email $email): bool
    {
        return false;
    }

    private function ownsVisibleInbox(User $user, ?Inbox $inbox): bool
    {
        if ($inbox === null || $inbox->trashed()) {
            return false;
        }

        if ($inbox->user_id === null || (string) $inbox->user_id !== (string) $user->getKey()) {
            return false;
        }

        if (! $inbox->isActive() || $inbox->isExpired()) {
            return false;
        }

        return true;
    }
}
