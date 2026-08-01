<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

final class SubscriptionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isPlatformAdmin();
    }

    public function view(User $actor, Subscription $subscription): bool
    {
        return $actor->isPlatformAdmin();
    }

    public function update(User $actor, Subscription $subscription): bool
    {
        return $actor->isPlatformAdmin();
    }
}
