<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Models\Domain;
use App\Models\OutboundDomainAuthentication;

/**
 * Validates the outbound staged-rollout configuration without sending mail
 * or mutating anything. Never auto-rewrites the environment — callers
 * (commands, the admin page, readiness) only ever read this report.
 */
final class OutboundLaunchConfigValidator
{
    public function __construct(
        private readonly OutboundCanaryService $canaries,
        private readonly OutboundTransportConfigValidator $transportValidator,
        private readonly OutboundQueueReadinessService $queueReadiness,
        private readonly OutboundLaunchControlService $launchControl,
    ) {}

    /**
     * @return array{valid: bool, errors: list<string>, warnings: list<string>, checks: array<string, mixed>}
     */
    public function validate(): array
    {
        $mode = $this->launchControl->mode();
        $percent = $this->launchControl->rawPercent();
        $emergencyStop = $this->launchControl->isEmergencyStopped();
        $outboundEnabled = (bool) config('outbound.enabled', false);
        $liveTrafficMode = in_array($mode, ['canary', 'percentage', 'enabled'], true);

        $errors = [];
        $warnings = [];

        $modeSupported = $this->launchControl->isSupportedMode($mode);
        if (! $modeSupported) {
            $errors[] = 'rollout_mode_unsupported';
        }

        $percentInRange = $percent >= 0 && $percent <= 100;
        if (! $percentInRange) {
            $errors[] = 'rollout_percent_out_of_range';
        }

        $hasActiveCanaries = $this->canaries->hasActiveCanaries();
        if ($mode === 'canary' && ! $hasActiveCanaries) {
            $errors[] = 'canary_mode_without_canaries';
        }

        $transportValid = $this->transportValidator->validate()['valid'];
        $queueReport = $this->queueReadiness->report();
        $queueValid = $queueReport['status'] !== 'failed';
        $verifiedDomainCount = $this->verifiedDomainCount();
        $webhookSecretPresent = $this->webhookSecretPresent();

        if ($modeSupported && $liveTrafficMode) {
            if (! $transportValid) {
                $errors[] = 'enabled_without_valid_transport';
            }
            if (! $queueValid) {
                $errors[] = 'enabled_without_ready_queues';
            }
            if ($verifiedDomainCount < 1) {
                $errors[] = 'enabled_without_verified_domain';
            }
            if (! $webhookSecretPresent) {
                $errors[] = 'enabled_without_webhook_secret';
            }
        }

        if (! $emergencyStop && ! $outboundEnabled && $liveTrafficMode) {
            $warnings[] = 'rollout_configured_while_globally_disabled';
        }

        if ($emergencyStop && $liveTrafficMode) {
            $warnings[] = 'emergency_stop_active_with_live_rollout_mode';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'checks' => [
                'mode' => $mode,
                'mode_supported' => $modeSupported,
                'percent' => $percent,
                'percent_in_range' => $percentInRange,
                'emergency_stop' => $emergencyStop,
                'outbound_enabled' => $outboundEnabled,
                'has_active_canaries' => $hasActiveCanaries,
                'transport_valid' => $transportValid,
                'queue_valid' => $queueValid,
                'verified_domain_count' => $verifiedDomainCount,
                'webhook_secret_present' => $webhookSecretPresent,
            ],
        ];
    }

    private function verifiedDomainCount(): int
    {
        return OutboundDomainAuthentication::query()
            ->whereIn('domain_id', Domain::query()->where('outbound_enabled', true)->where('is_active', true)->pluck('id'))
            ->where('state', 'verified')
            ->count();
    }

    private function webhookSecretPresent(): bool
    {
        $provider = strtolower((string) config('outbound.provider', 'generic'));

        if ($provider === 'ses') {
            return trim((string) config('outbound.delivery_webhook.providers.ses.topic_arn', '')) !== '';
        }

        return trim((string) config('outbound.delivery_webhook.providers.generic.secret', '')) !== '';
    }
}
