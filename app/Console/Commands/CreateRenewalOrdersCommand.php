<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Billing\CreateRenewalOrdersJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

final class CreateRenewalOrdersCommand extends Command
{
    protected $signature = 'billing:create-renewal-orders';

    protected $description = 'Create idempotent renewal orders for subscriptions approaching term end.';

    public function handle(): int
    {
        Bus::dispatchSync(new CreateRenewalOrdersJob);

        return self::SUCCESS;
    }
}
