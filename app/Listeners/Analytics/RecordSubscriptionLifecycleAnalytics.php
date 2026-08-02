<?php

declare(strict_types=1);

namespace App\Listeners\Analytics;

use App\Enums\AnalyticsDomain;
use App\Enums\AnalyticsMetricKey;
use App\Events\SubscriptionLifecycleEvent;
use App\Services\Analytics\AnalyticsEventCollector;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RecordSubscriptionLifecycleAnalytics
{
    public function __construct(
        private readonly AnalyticsEventCollector $collector,
    ) {}

    public function handle(SubscriptionLifecycleEvent $event): void
    {
        try {
            $this->collector->record(
                AnalyticsDomain::Billing,
                AnalyticsMetricKey::BillingOrders,
                1,
                now(),
                (string) $event->subscription->user_id,
                SubscriptionLifecycleEvent::class,
                [
                    'lifecycle' => $event->name,
                    'subscription_id' => (string) $event->subscription->getKey(),
                    'plan_id' => (string) $event->subscription->plan_id,
                    'status' => $event->subscription->status instanceof \BackedEnum
                        ? $event->subscription->status->value
                        : (string) $event->subscription->status,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('analytics.subscription_lifecycle_ingest_failed', ['message' => $e->getMessage()]);
        }
    }
}
