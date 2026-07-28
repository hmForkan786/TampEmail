<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Billing\ExpireSubscriptionsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

final class ExpireLifecycleSubscriptionsCommand extends Command
{
    protected $signature = 'billing:expire-lifecycle-subscriptions';

    protected $description = 'Expire trials and grace periods that reached their configured boundary.';

    public function handle(): int
    {
        Bus::dispatchSync(new ExpireSubscriptionsJob);

        return self::SUCCESS;
    }
}
