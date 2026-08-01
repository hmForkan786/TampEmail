<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Services\Subscription\SubscriptionRenewalScheduler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CreateRenewalOrdersJob implements ShouldQueue
{
    use Queueable;

    public function handle(SubscriptionRenewalScheduler $scheduler): void
    {
        $scheduler->createRenewalOrders();
    }
}
