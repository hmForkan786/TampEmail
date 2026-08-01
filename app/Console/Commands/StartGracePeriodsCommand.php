<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Billing\StartGracePeriodJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

final class StartGracePeriodsCommand extends Command
{
    protected $signature = 'billing:start-grace-periods';

    protected $description = 'Start due grace periods and emit idempotent grace reminders.';

    public function handle(): int
    {
        Bus::dispatchSync(new StartGracePeriodJob);

        return self::SUCCESS;
    }
}
