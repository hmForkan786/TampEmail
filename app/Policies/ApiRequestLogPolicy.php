<?php
declare(strict_types=1);
namespace App\Policies;
use App\Models\ApiRequestLog;
use App\Models\User;
final class ApiRequestLogPolicy
{
    public function viewAny(User $actor): bool { return $actor->isPlatformAdmin(); }
    public function view(User $actor, ApiRequestLog $log): bool { return $actor->isPlatformAdmin(); }
    public function create(User $actor): bool { return false; }
    public function update(User $actor, ApiRequestLog $log): bool { return false; }
    public function delete(User $actor, ApiRequestLog $log): bool { return false; }
    public function deleteAny(User $actor): bool { return false; }
    public function restore(User $actor, ApiRequestLog $log): bool { return false; }
    public function restoreAny(User $actor): bool { return false; }
    public function forceDelete(User $actor, ApiRequestLog $log): bool { return false; }
    public function forceDeleteAny(User $actor): bool { return false; }
}
