<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundLaunchRecommendation;

/**
 * Advisory continue|hold|rollback recommendation derived from launch
 * metrics vs. configurable thresholds. Purely informational: nothing here
 * auto-disables outbound. An operator must act on the recommendation
 * (e.g. flip the emergency stop or rollout mode) explicitly.
 */
final class OutboundLaunchRecommendationService
{
    public function __construct(
        private readonly OutboundLaunchMetricsService $metrics,
    ) {}

    /**
     * @return array{recommendation: string, reasons: list<string>, metrics: array<string, mixed>, thresholds: array<string, mixed>}
     */
    public function recommend(): array
    {
        $metrics = $this->metrics->metrics();
        $thresholds = (array) config('outbound.launch.thresholds', []);

        $rollbackReasons = [];
        $holdReasons = [];

        if ($metrics['bounce_rate_percent'] >= (float) ($thresholds['rollback_bounce_rate_percent'] ?? 10)) {
            $rollbackReasons[] = 'bounce_rate_critical';
        } elseif ($metrics['bounce_rate_percent'] >= (float) ($thresholds['hold_bounce_rate_percent'] ?? 5)) {
            $holdReasons[] = 'bounce_rate_elevated';
        }

        if ($metrics['complaint_rate_percent'] >= (float) ($thresholds['rollback_complaint_rate_percent'] ?? 3)) {
            $rollbackReasons[] = 'complaint_rate_critical';
        } elseif ($metrics['complaint_rate_percent'] >= (float) ($thresholds['hold_complaint_rate_percent'] ?? 1)) {
            $holdReasons[] = 'complaint_rate_elevated';
        }

        if ($metrics['oldest_queued_age_seconds'] >= (int) ($thresholds['oldest_queue_age_seconds'] ?? 1800)) {
            $holdReasons[] = 'queue_age_elevated';
        }

        if ($metrics['invalid_signature_attempts'] >= (int) ($thresholds['invalid_signature_attempts'] ?? 5)) {
            $rollbackReasons[] = 'invalid_signature_surge';
        }

        if ($metrics['provider_auth_failures'] >= (int) ($thresholds['provider_auth_failures'] ?? 3)) {
            $rollbackReasons[] = 'provider_auth_failures_critical';
        }

        if ($metrics['unmatched_events'] >= (int) ($thresholds['unmatched_events'] ?? 10)) {
            $holdReasons[] = 'unmatched_events_elevated';
        }

        if ($metrics['ambiguous_acceptance'] >= (int) ($thresholds['ambiguous_acceptance'] ?? 5)) {
            $holdReasons[] = 'ambiguous_acceptance_elevated';
        }

        $workerHealth = $metrics['worker_health'] ?? [];
        $missingHeartbeats = ($workerHealth['delivery_fresh_workers'] ?? 0) === 0
            || ($workerHealth['events_fresh_workers'] ?? 0) === 0
            || ($workerHealth['scheduler_fresh'] ?? false) === false;
        if ($missingHeartbeats) {
            $holdReasons[] = 'worker_or_scheduler_heartbeat_missing';
        }

        $recommendation = match (true) {
            $rollbackReasons !== [] => OutboundLaunchRecommendation::Rollback,
            $holdReasons !== [] => OutboundLaunchRecommendation::Hold,
            default => OutboundLaunchRecommendation::Continue,
        };

        return [
            'recommendation' => $recommendation->value,
            'reasons' => array_values([...$rollbackReasons, ...$holdReasons]),
            'metrics' => $metrics,
            'thresholds' => $thresholds,
        ];
    }
}
