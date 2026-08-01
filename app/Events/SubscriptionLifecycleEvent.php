<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Subscription;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SubscriptionLifecycleEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $name,
        public readonly Subscription $subscription,
        public readonly array $context = [],
    ) {}
}
