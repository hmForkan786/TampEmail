<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ads\AdStatisticsService;
use Illuminate\Console\Command;

final class AdsPruneStatisticsCommand extends Command
{
    protected $signature = 'ads:prune-statistics {--confirm : Required to delete retained rows}';

    protected $description = 'Prune old ad impression and click rows per retention policy';

    public function handle(AdStatisticsService $statistics): int
    {
        if (! $this->option('confirm')) {
            $this->error('Refusing to prune without --confirm.');

            return self::FAILURE;
        }

        $result = $statistics->prune(
            (int) config('ads.statistics.impression_retention_days', 90),
            (int) config('ads.statistics.click_retention_days', 90),
        );

        $this->info(sprintf(
            'Pruned %d impression(s) and %d click(s).',
            $result['impressions_deleted'],
            $result['clicks_deleted'],
        ));

        return self::SUCCESS;
    }
}
