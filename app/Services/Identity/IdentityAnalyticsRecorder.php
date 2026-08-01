<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\AnalyticsDomain;
use App\Enums\AnalyticsMetricKey;
use App\Services\Analytics\AnalyticsEventCollector;
use Throwable;

/**
 * PII-safe identity analytics emitter. Fail-open relative to product flows.
 */
final class IdentityAnalyticsRecorder
{
    public function __construct(
        private readonly AnalyticsEventCollector $collector,
    ) {}

    public function record(string $sourceEvent, ?string $ownerId = null, int $value = 1): void
    {
        try {
            $metric = match (true) {
                str_contains($sourceEvent, 'registration') => AnalyticsMetricKey::UsersRegistrations,
                str_contains($sourceEvent, 'login_succeeded') => AnalyticsMetricKey::UsersActive,
                default => AnalyticsMetricKey::UsersRegistrations,
            };

            $this->collector->record(
                AnalyticsDomain::Users,
                $metric,
                $value,
                ownerId: $ownerId,
                sourceEvent: $sourceEvent,
            );
        } catch (Throwable) {
            // Fail-open.
        }
    }
}
