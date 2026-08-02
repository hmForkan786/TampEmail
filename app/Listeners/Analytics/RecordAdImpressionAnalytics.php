<?php

declare(strict_types=1);

namespace App\Listeners\Analytics;

use App\Enums\AnalyticsDomain;
use App\Enums\AnalyticsMetricKey;
use App\Events\Ads\AdRendered;
use App\Services\Analytics\AnalyticsEventCollector;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RecordAdImpressionAnalytics
{
    public function __construct(
        private readonly AnalyticsEventCollector $collector,
    ) {}

    public function handle(AdRendered $event): void
    {
        try {
            $this->collector->record(
                AnalyticsDomain::Ads,
                AnalyticsMetricKey::AdsImpressions,
                1,
                $event->impression->created_at,
                $event->impression->user_id,
                AdRendered::class,
                [
                    'campaign_id' => (string) $event->campaign->getKey(),
                    'placement_id' => (string) $event->placement->getKey(),
                    'impression_id' => (string) $event->impression->getKey(),
                ],
            );
        } catch (Throwable $e) {
            Log::warning('analytics.ad_impression_ingest_failed', ['message' => $e->getMessage()]);
        }
    }
}
