<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsAggregationStatus;
use App\Models\AnalyticsAggregationRun;
use App\Models\AnalyticsDailyRollup;
use App\Models\AnalyticsEvent;

/**
 * Ops health for the Analytics aggregation pipeline.
 */
final class AnalyticsHealthCheckService
{
    /**
     * @return array{
     *     healthy: bool,
     *     enabled: bool,
     *     last_success_at: string|null,
     *     last_failure_at: string|null,
     *     backlog_days: int,
     *     failed_runs_24h: int,
     *     events_total: int,
     *     rollups_total: int,
     *     reason: string|null
     * }
     */
    public function check(): array
    {
        $enabled = (bool) config('analytics.enabled', true);
        $backfillDays = (int) config('analytics.rollup.backfill_days', 7);

        $lastSuccess = AnalyticsAggregationRun::query()
            ->where('status', AnalyticsAggregationStatus::Succeeded->value)
            ->orderByDesc('finished_at')
            ->first();

        $lastFailure = AnalyticsAggregationRun::query()
            ->where('status', AnalyticsAggregationStatus::Failed->value)
            ->orderByDesc('finished_at')
            ->first();

        $failed24h = AnalyticsAggregationRun::query()
            ->where('status', AnalyticsAggregationStatus::Failed->value)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $backlog = 0;
        for ($i = 1; $i <= $backfillDays; $i++) {
            $date = now()->subDays($i)->toDateString();
            $ok = AnalyticsAggregationRun::query()
                ->whereDate('bucket_date', $date)
                ->where('status', AnalyticsAggregationStatus::Succeeded->value)
                ->exists();
            if (! $ok) {
                $backlog++;
            }
        }

        $result = [
            'healthy' => true,
            'enabled' => $enabled,
            'last_success_at' => $lastSuccess?->finished_at?->toIso8601String(),
            'last_failure_at' => $lastFailure?->finished_at?->toIso8601String(),
            'backlog_days' => $backlog,
            'failed_runs_24h' => $failed24h,
            'events_total' => AnalyticsEvent::query()->count(),
            'rollups_total' => AnalyticsDailyRollup::query()->count(),
            'reason' => null,
        ];

        if (! $enabled) {
            return $result;
        }

        if ($failed24h > 0) {
            $result['healthy'] = false;
            $result['reason'] = 'failed_aggregation';
        } elseif ($backlog > max(1, (int) floor($backfillDays / 2))) {
            $result['healthy'] = false;
            $result['reason'] = 'aggregation_backlog';
        }

        return $result;
    }
}
