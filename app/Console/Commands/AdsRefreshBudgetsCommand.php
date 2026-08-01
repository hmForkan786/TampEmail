<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ads\AdCampaignLifecycleService;
use Illuminate\Console\Command;

final class AdsRefreshBudgetsCommand extends Command
{
    protected $signature = 'ads:refresh-budgets';

    protected $description = 'Reset daily impression/click counters for ad campaigns';

    public function handle(AdCampaignLifecycleService $lifecycle): int
    {
        $count = $lifecycle->refreshDailyBudgets();
        $this->info("Refreshed daily budgets for {$count} campaign(s).");

        return self::SUCCESS;
    }
}
