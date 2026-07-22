<?php
declare(strict_types=1);
namespace App\Policies;
use App\Models\AuditLogHold;
use App\Models\User;
final class AuditLogHoldPolicy
{
    public function viewAny(User $user): bool { return $user->isPlatformAdmin(); }
    public function view(User $user, AuditLogHold $hold): bool { return $user->isPlatformAdmin(); }
    public function create(User $user): bool { return $user->isPlatformAdmin(); }
    public function update(User $user, AuditLogHold $hold): bool { return false; }
    public function delete(User $user, AuditLogHold $hold): bool { return false; }
    public function deleteAny(User $user): bool { return false; }
    public function restore(User $user, AuditLogHold $hold): bool { return false; }
    public function restoreAny(User $user): bool { return false; }
    public function forceDelete(User $user, AuditLogHold $hold): bool { return false; }
    public function forceDeleteAny(User $user): bool { return false; }
}
