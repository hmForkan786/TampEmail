<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\AnalyticsDomain;
use App\Enums\AnalyticsMetricKey;
use App\Services\Analytics\AnalyticsEventCollector;
use Throwable;

/**
 * PII-safe settings analytics emitter. Fail-open relative to product flows.
 */
final class SettingsAnalyticsRecorder
{
    public function __construct(
        private readonly AnalyticsEventCollector $collector,
    ) {}

    /**
     * @param  array<string, scalar|null>  $dimensions
     */
    public function record(string $sourceEvent, ?string $ownerId = null, int $value = 1, array $dimensions = []): void
    {
        try {
            $metric = match (true) {
                str_contains($sourceEvent, 'api_key') => AnalyticsMetricKey::ApiKeyUsage,
                str_contains($sourceEvent, 'billing') => AnalyticsMetricKey::BillingOrders,
                default => AnalyticsMetricKey::UsersActive,
            };

            $this->collector->record(
                AnalyticsDomain::Users,
                $metric,
                $value,
                ownerId: $ownerId,
                sourceEvent: $sourceEvent,
                dimensions: $dimensions,
            );
        } catch (Throwable) {
            // Fail-open when analytics storage is unavailable.
        }
    }
}
