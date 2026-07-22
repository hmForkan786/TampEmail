<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MailServer;
use App\Models\User;

final class MailServerPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isPlatformOperator();
    }

    public function view(User $actor, MailServer $mailServer): bool
    {
        return $actor->isPlatformOperator();
    }

    public function create(User $actor): bool { return $actor->isPlatformOperator(); }
    public function update(User $actor, MailServer $mailServer): bool { return $actor->isPlatformOperator(); }
    public function delete(User $actor, MailServer $mailServer): bool { return false; }
    public function deleteAny(User $actor): bool { return false; }
    public function restore(User $actor, MailServer $mailServer): bool { return false; }
    public function restoreAny(User $actor): bool { return false; }
    public function forceDelete(User $actor, MailServer $mailServer): bool { return false; }
    public function forceDeleteAny(User $actor): bool { return false; }
}
