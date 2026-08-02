<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\AnalyticsAggregationRun;
use App\Models\AnalyticsDailyRollup;
use App\Models\AnalyticsEvent;
use Illuminate\Support\Carbon;

final class AnalyticsPruneService
{
    /**
     * @return array{events: int, rollups: int, runs: int}
     */
    public function prune(): array
    {
        $eventsDays = (int) config('analytics.retention.events_days', 90);
        $rollupsDays = (int) config('analytics.retention.rollups_days', 730);
        $runsDays = (int) config('analytics.retention.runs_days', 180);

        $events = AnalyticsEvent::query()
            ->where('occurred_at', '<', Carbon::now()->subDays($eventsDays))
            ->delete();

        $rollups = AnalyticsDailyRollup::query()
            ->where('bucket_date', '<', Carbon::now()->subDays($rollupsDays)->toDateString())
            ->delete();

        $runs = AnalyticsAggregationRun::query()
            ->where('created_at', '<', Carbon::now()->subDays($runsDays))
            ->delete();

        return [
            'events' => (int) $events,
            'rollups' => (int) $rollups,
            'runs' => (int) $runs,
        ];
    }
}
