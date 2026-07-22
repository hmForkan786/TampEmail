<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\User;

/**
 * Authorization for the admin API Key Filament resource.
 *
 * Read access is limited to active platform admins. Mutations are always denied.
 */
final class ApiKeyPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isPlatformAdmin();
    }

    public function view(User $actor, ApiKey $apiKey): bool
    {
        return $actor->isPlatformAdmin();
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, ApiKey $apiKey): bool
    {
        return false;
    }

    public function delete(User $actor, ApiKey $apiKey): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, ApiKey $apiKey): bool
    {
        return false;
    }

    public function restoreAny(User $actor): bool
    {
        return false;
    }

    public function forceDelete(User $actor, ApiKey $apiKey): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }
}
