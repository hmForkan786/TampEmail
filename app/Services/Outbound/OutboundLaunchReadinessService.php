<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Models\Domain;
use App\Models\Feature;
use App\Models\OutboundAbuseBlock;
use App\Models\OutboundDomainAuthentication;
use App\Models\OutboundRecipientSuppression;
use Illuminate\Support\Facades\Schema;

/**
 * Production launch readiness gate for outbound email (Prompt 615).
 *
 * Evaluates transport, provider credentials/parser/webhook, queues, worker
 * and scheduler heartbeats, domain verification, feature flags, plan
 * entitlements, suppression/abuse subsystem availability, migrations, and
 * the staged-rollout/emergency-stop controls. Never sends a test email —
 * that is the explicit, opt-in job of `outbound:canary-send`.
 */
final class OutboundLaunchReadinessService
{
    private const REQUIRED_TABLES = [
        'outbound_messages',
        'outbound_delivery_attempts',
        'outbound_provider_events',
        'outbound_recipient_suppressions',
        'outbound_abuse_blocks',
        'outbound_domain_authentications',
        'outbound_launch_canaries',
    ];

    private const PLAN_FEATURE_KEYS = ['send_email', 'reply_email', 'forward_email'];

    public function __construct(
        private readonly OutboundTransportConfigValidator $transportValidator,
        private readonly OutboundQueueReadinessService $queueReadiness,
        private readonly OutboundLaunchConfigValidator $configValidator,
        private readonly OutboundProviderEventParserRegistry $parserRegistry,
        private readonly OutboundLaunchControlService $launchControl,
        private readonly OutboundCanaryService $canaries,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(): array
    {
        $migrations = $this->migrationsReadiness();
        $featureFlags = $this->featureFlags();
        $transport = $this->transportValidator->validate();
        $queue = $this->queueReadiness->report();
        $provider = $this->providerReadiness();
        $domain = $this->domainReadiness();
        $planFeatures = $this->planFeaturesReadiness();
        $suppression = $this->subsystemOperational(fn (): int => OutboundRecipientSuppression::query()->count());
        $abuse = $this->subsystemOperational(fn (): int => OutboundAbuseBlock::query()->count());
        $configValidation = $this->configValidator->validate();
        $mode = $this->launchControl->mode();
        $emergencyStop = $this->launchControl->isEmergencyStopped();

        $rollout = [
            'mode' => $mode,
            'mode_supported' => $this->launchControl->isSupportedMode($mode),
            'percent' => $this->launchControl->percent(),
            'emergency_stop' => $emergencyStop,
            'active_canaries' => $this->canaries->active()->count(),
        ];

        $checks = [
            'migrations' => $migrations,
            'feature_flags' => $featureFlags,
            'transport' => $transport,
            'queue' => $queue,
            'provider' => $provider,
            'domain' => $domain,
            'plan_features' => $planFeatures,
            'suppression' => $suppression,
            'abuse' => $abuse,
            'rollout' => $rollout,
            'config_validation' => $configValidation,
        ];

        [$status, $reasons] = $this->status($checks, $featureFlags, $mode, $emergencyStop);

        return [
            'status' => $status,
            'reasons' => $reasons,
            'evaluated_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    /**
     * @param  array<string, mixed>  $checks
     * @return array{0: string, 1: list<string>}
     */
    private function status(array $checks, array $featureFlags, string $mode, bool $emergencyStop): array
    {
        $reasons = [];

        if (! $checks['migrations']['complete']) {
            return ['blocked', ['migrations_pending']];
        }

        if (! $featureFlags['outbound_enabled']) {
            return ['disabled', ['outbound_disabled']];
        }

        if ($emergencyStop) {
            return ['disabled', ['emergency_stop_active']];
        }

        if ($mode === 'disabled') {
            return ['disabled', ['rollout_mode_disabled']];
        }

        if (! $checks['rollout']['mode_supported']) {
            return ['blocked', ['rollout_mode_invalid']];
        }

        if (! $checks['config_validation']['valid']) {
            return ['blocked', $checks['config_validation']['errors']];
        }

        if (! $checks['transport']['valid']) {
            $reasons[] = 'transport_invalid';
        }
        if ($checks['queue']['status'] === 'failed') {
            $reasons[] = 'queue_invalid';
        }
        if (! $checks['provider']['parser_resolves']) {
            $reasons[] = 'provider_parser_unresolved';
        }
        if (! $checks['provider']['webhook_secret_present']) {
            $reasons[] = 'provider_webhook_secret_missing';
        }
        if ($checks['domain']['verified_count'] < 1) {
            $reasons[] = 'no_verified_outbound_domain';
        }
        if (! $checks['plan_features']['all_present']) {
            $reasons[] = 'plan_features_missing';
        }
        if (! $checks['suppression']['operational']) {
            $reasons[] = 'suppression_subsystem_unavailable';
        }
        if (! $checks['abuse']['operational']) {
            $reasons[] = 'abuse_subsystem_unavailable';
        }

        if ($reasons !== []) {
            return ['blocked', $reasons];
        }

        if ($checks['queue']['status'] === 'degraded') {
            $reasons[] = 'queue_degraded';
        }
        if ($checks['domain']['pending_count'] > 0 || $checks['domain']['failed_count'] > 0) {
            $reasons[] = 'domain_verification_incomplete';
        }
        if ($checks['config_validation']['warnings'] !== []) {
            $reasons = [...$reasons, ...$checks['config_validation']['warnings']];
        }

        if ($reasons !== []) {
            return ['degraded', $reasons];
        }

        return ['ready', []];
    }

    /**
     * @return array{complete: bool, missing_tables: list<string>}
     */
    private function migrationsReadiness(): array
    {
        $missing = [];
        foreach (self::REQUIRED_TABLES as $table) {
            try {
                if (! Schema::hasTable($table)) {
                    $missing[] = $table;
                }
            } catch (\Throwable) {
                $missing[] = $table;
            }
        }

        return [
            'complete' => $missing === [],
            'missing_tables' => $missing,
        ];
    }

    /**
     * @return array{outbound_enabled: bool, send_enabled: bool, reply_enabled: bool, forward_enabled: bool}
     */
    private function featureFlags(): array
    {
        return [
            'outbound_enabled' => (bool) config('outbound.enabled', false),
            'send_enabled' => (bool) config('outbound.send_enabled', false),
            'reply_enabled' => (bool) config('outbound.reply_enabled', false),
            'forward_enabled' => (bool) config('outbound.forward_enabled', false),
        ];
    }

    /**
     * @return array{provider: string, parser_resolves: bool, webhook_secret_present: bool}
     */
    private function providerReadiness(): array
    {
        $provider = strtolower((string) config('outbound.provider', 'generic'));

        $parserResolves = true;
        try {
            $this->parserRegistry->for($provider);
        } catch (\Throwable) {
            $parserResolves = false;
        }

        $webhookSecretPresent = $provider === 'ses'
            ? trim((string) config('outbound.delivery_webhook.providers.ses.topic_arn', '')) !== ''
            : trim((string) config('outbound.delivery_webhook.providers.generic.secret', '')) !== '';

        return [
            'provider' => $provider,
            'parser_resolves' => $parserResolves,
            'webhook_secret_present' => $webhookSecretPresent,
        ];
    }

    /**
     * @return array{verified_count: int, pending_count: int, failed_count: int, degraded_count: int}
     */
    private function domainReadiness(): array
    {
        $outboundDomainIds = Domain::query()->where('outbound_enabled', true)->where('is_active', true)->pluck('id');

        $base = OutboundDomainAuthentication::query()->whereIn('domain_id', $outboundDomainIds);

        return [
            'verified_count' => (clone $base)->where('state', 'verified')->count(),
            'degraded_count' => (clone $base)->where('state', 'degraded')->count(),
            'pending_count' => (clone $base)->whereIn('state', ['pending', 'unconfigured'])->count(),
            'failed_count' => (clone $base)->where('state', 'failed')->count(),
        ];
    }

    /**
     * @return array{all_present: bool, missing: list<string>}
     */
    private function planFeaturesReadiness(): array
    {
        $present = Feature::query()
            ->whereIn('key', self::PLAN_FEATURE_KEYS)
            ->where('is_active', true)
            ->pluck('key')
            ->all();

        $missing = array_values(array_diff(self::PLAN_FEATURE_KEYS, $present));

        return [
            'all_present' => $missing === [],
            'missing' => $missing,
        ];
    }

    /**
     * @param  callable(): int  $probe
     * @return array{operational: bool}
     */
    private function subsystemOperational(callable $probe): array
    {
        try {
            $probe();

            return ['operational' => true];
        } catch (\Throwable) {
            return ['operational' => false];
        }
    }
}
