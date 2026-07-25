<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundMessageState;
use App\Models\Domain;
use App\Models\OutboundDomainAuthentication;
use App\Models\OutboundMessage;
use Illuminate\Support\Carbon;

/**
 * Launch metrics for the staged outbound rollout: canary volume, delivery
 * outcomes, bounce/complaint rates, suppressions, abuse blocks, queue
 * health, and reconciliation signals. Reuses {@see OutboundOpsService} and
 * {@see OutboundQueueReadinessService} rather than re-deriving their
 * queries.
 */
final class OutboundLaunchMetricsService
{
    public function __construct(
        private readonly OutboundOpsService $ops,
        private readonly OutboundQueueReadinessService $queueReadiness,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function metrics(?Carbon $since = null): array
    {
        $sinceCarbon = $since ?? now()->subDay();

        $provider = $this->ops->providerMetrics($sinceCarbon);
        $suppressions = $this->ops->suppressionMetrics();
        $abuse = $this->ops->abuseMetrics();
        $retries = $this->ops->retryMetrics();
        $queue = $this->queueReadiness->report();

        $canary = $this->canaryVolume($sinceCarbon);
        $sentCount = OutboundMessage::query()
            ->whereIn('state', [OutboundMessageState::Sent->value, OutboundMessageState::Delivered->value])
            ->where('created_at', '>=', $sinceCarbon)
            ->count();

        $ambiguousAcceptance = OutboundMessage::query()
            ->whereNotNull('reconciliation_flagged_at')
            ->where('created_at', '>=', $sinceCarbon)
            ->count();

        $providerAuthFailures = OutboundMessage::query()
            ->where('state', OutboundMessageState::Failed->value)
            ->where('failed_at', '>=', $sinceCarbon)
            ->whereIn('failure_code', ['credentials_rejected', 'tls_configuration', 'invalid_config'])
            ->count();

        $bounceRatePercent = $sentCount > 0 ? round(($provider['bounced'] / $sentCount) * 100, 2) : 0.0;
        $complaintRatePercent = $sentCount > 0 ? round(($provider['complained'] / $sentCount) * 100, 2) : 0.0;

        return [
            'evaluated_at' => now()->toIso8601String(),
            'window_since' => $sinceCarbon->toIso8601String(),
            'canary' => $canary,
            'accepted' => $provider['accepted'],
            'delivered' => $provider['delivered'],
            'temporary_failures' => $provider['temporary_rejections'],
            'permanent_failures' => $provider['permanent_rejections'],
            'bounce_rate_percent' => $bounceRatePercent,
            'complaint_rate_percent' => $complaintRatePercent,
            'suppressions_active' => $suppressions['active'],
            'suppressions_added' => $suppressions['added_24h'],
            'abuse_blocked_sends' => $abuse['blocked_sends'],
            'oldest_queued_age_seconds' => $retries['oldest_queued_age_seconds'],
            'retries_exhausted' => $retries['retries_exhausted'],
            'provider_auth_failures' => $providerAuthFailures,
            'unmatched_events' => $provider['unmatched_provider_events'],
            'terminal_unmatched_events' => $provider['terminal_unmatched_events'],
            'ambiguous_acceptance' => $ambiguousAcceptance,
            'invalid_signature_attempts' => $provider['invalid_signature_attempts'],
            'worker_health' => [
                'status' => $queue['status'],
                'delivery_fresh_workers' => $queue['delivery']['fresh_workers'] ?? 0,
                'events_fresh_workers' => $queue['events']['fresh_workers'] ?? 0,
                'scheduler_fresh' => $queue['maintenance']['scheduler_fresh'] ?? false,
            ],
            'verified_domain_count' => $this->verifiedDomainCount(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function canaryVolume(Carbon $since): array
    {
        $base = OutboundMessage::query()->where('is_canary', true)->where('created_at', '>=', $since);

        return [
            'sends' => (clone $base)->count(),
            'accepted' => (clone $base)->whereIn('state', [OutboundMessageState::Sent->value, OutboundMessageState::Delivered->value])->count(),
            'delivered' => (clone $base)->where('state', OutboundMessageState::Delivered->value)->count(),
            'failed' => (clone $base)->where('state', OutboundMessageState::Failed->value)->count(),
        ];
    }

    private function verifiedDomainCount(): int
    {
        return OutboundDomainAuthentication::query()
            ->whereIn('domain_id', Domain::query()->where('outbound_enabled', true)->where('is_active', true)->pluck('id'))
            ->where('state', 'verified')
            ->count();
    }
}
