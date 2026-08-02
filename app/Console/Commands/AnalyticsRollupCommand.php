<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsAggregationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

final class AnalyticsRollupCommand extends Command
{
    protected $signature = 'analytics:rollup
                            {--date= : Calendar date (Y-m-d) to roll up; default yesterday}
                            {--backfill : Roll up missing days in the configured backfill window}';

    protected $description = 'Aggregate subsystem metrics into analytics daily rollups (read model)';

    public function handle(AnalyticsAggregationService $aggregation): int
    {
        try {
            if ($this->option('backfill')) {
                $results = $aggregation->rollupBackfill();
                $this->line(json_encode([
                    'mode' => 'backfill',
                    'days' => $results,
                ], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $date = $this->option('date')
                ? Carbon::parse((string) $this->option('date'))->startOfDay()
                : now()->subDay()->startOfDay();

            $result = $aggregation->rollupDay($date);
            $this->line(json_encode([
                'mode' => 'day',
                'date' => $date->toDateString(),
                'metrics_written' => $result['metrics_written'],
                'run_id' => $result['run']->getKey(),
                'status' => $result['run']->status instanceof \BackedEnum
                    ? $result['run']->status->value
                    : (string) $result['run']->status,
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
