<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Plan;
use App\Models\User;

final class PlanPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isPlatformAdmin();
    }

    public function view(User $actor, Plan $plan): bool
    {
        return $actor->isPlatformAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isPlatformAdmin();
    }

    public function update(User $actor, Plan $plan): bool
    {
        return $actor->isPlatformAdmin();
    }

    public function delete(User $actor, Plan $plan): bool
    {
        return $actor->isPlatformAdmin() && ! in_array($plan->slug, ['free', 'premium'], true) && ! $plan->subscriptions()->whereIn('status', ['active', 'trial'])->exists();
    }
}
