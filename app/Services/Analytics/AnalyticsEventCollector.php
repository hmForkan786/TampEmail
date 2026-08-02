<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsDomain;
use App\Enums\AnalyticsMetricKey;
use App\Models\AnalyticsEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Sanitized event ingest for the Analytics read model.
 * Never executes business logic; never stores PII keys.
 */
final class AnalyticsEventCollector
{
    public function enabled(): bool
    {
        return (bool) config('analytics.enabled', true);
    }

    /**
     * @param  array<string, mixed>  $dimensions
     */
    public function record(
        AnalyticsDomain $domain,
        AnalyticsMetricKey $metric,
        float|int $value = 1,
        ?CarbonInterface $occurredAt = null,
        ?string $ownerId = null,
        ?string $sourceEvent = null,
        array $dimensions = [],
    ): ?AnalyticsEvent {
        if (! $this->enabled()) {
            return null;
        }

        return AnalyticsEvent::query()->create([
            'domain' => $domain->value,
            'metric_key' => $metric->value,
            'value' => $value,
            'occurred_at' => $occurredAt?->toDateTimeString() ?? now()->toDateTimeString(),
            'owner_id' => $ownerId,
            'source_event' => $sourceEvent,
            'dimensions' => $this->sanitizeDimensions($dimensions),
        ]);
    }

    /**
     * @param  array<string, mixed>  $dimensions
     * @return array<string, mixed>
     */
    public function sanitizeDimensions(array $dimensions): array
    {
        /** @var list<string> $deny */
        $deny = array_map('strtolower', (array) config('analytics.pii_deny_keys', []));
        $clean = [];

        foreach ($dimensions as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $normalized = strtolower($key);
            if (in_array($normalized, $deny, true)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    public function countSince(CarbonInterface $since): int
    {
        return AnalyticsEvent::query()
            ->where('occurred_at', '>=', Carbon::instance($since)->toDateTimeString())
            ->count();
    }
}
