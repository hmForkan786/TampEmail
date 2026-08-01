<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ads\AdCampaignLifecycleService;
use Illuminate\Console\Command;

final class AdsExpireCampaignsCommand extends Command
{
    protected $signature = 'ads:expire-campaigns';

    protected $description = 'Mark active ad campaigns past their end date as expired';

    public function handle(AdCampaignLifecycleService $lifecycle): int
    {
        $count = $lifecycle->expireDueCampaigns();
        $this->info("Expired {$count} campaign(s).");

        return self::SUCCESS;
    }
}
