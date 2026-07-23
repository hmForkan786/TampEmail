<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmailProcessingLog;
use App\Models\User;

/** Read-only authorization for the inbound failure/DLQ operational view. */
final class InboundFailurePolicy
{
    public function viewAny(User $actor): bool { return $actor->isPlatformAdmin(); }
    public function view(User $actor, EmailProcessingLog $failure): bool { return $actor->isPlatformAdmin(); }
    public function create(User $actor): bool { return false; }
    public function update(User $actor, EmailProcessingLog $failure): bool { return false; }
    public function delete(User $actor, EmailProcessingLog $failure): bool { return false; }
    public function deleteAny(User $actor): bool { return false; }
    public function restore(User $actor, EmailProcessingLog $failure): bool { return false; }
    public function restoreAny(User $actor): bool { return false; }
    public function forceDelete(User $actor, EmailProcessingLog $failure): bool { return false; }
    public function forceDeleteAny(User $actor): bool { return false; }
}
