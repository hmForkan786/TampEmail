<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsPruneService;
use Illuminate\Console\Command;

final class AnalyticsPruneCommand extends Command
{
    protected $signature = 'analytics:prune {--confirm : Required safety flag to delete expired analytics rows}';

    protected $description = 'Prune analytics events, rollups, and aggregation runs past retention';

    public function handle(AnalyticsPruneService $prune): int
    {
        if (! $this->option('confirm')) {
            $this->error('Refusing to prune without --confirm');

            return self::FAILURE;
        }

        $result = $prune->prune();
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
