<?php

declare(strict_types=1);

namespace App\Listeners\Analytics;

use App\Enums\AnalyticsDomain;
use App\Enums\AnalyticsMetricKey;
use App\Events\Ads\AdClicked;
use App\Services\Analytics\AnalyticsEventCollector;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RecordAdClickAnalytics
{
    public function __construct(
        private readonly AnalyticsEventCollector $collector,
    ) {}

    public function handle(AdClicked $event): void
    {
        try {
            $this->collector->record(
                AnalyticsDomain::Ads,
                AnalyticsMetricKey::AdsClicks,
                1,
                $event->click->created_at,
                $event->click->user_id,
                AdClicked::class,
                [
                    'campaign_id' => (string) $event->campaign->getKey(),
                    'placement_id' => (string) $event->placement->getKey(),
                    'click_id' => (string) $event->click->getKey(),
                ],
            );
        } catch (Throwable $e) {
            Log::warning('analytics.ad_click_ingest_failed', ['message' => $e->getMessage()]);
        }
    }
}
