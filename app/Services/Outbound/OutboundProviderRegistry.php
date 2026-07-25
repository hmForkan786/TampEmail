<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Contracts\OutboundProviderEventParserInterface;
use App\Contracts\OutboundTransportInterface;
use App\Enums\OutboundDomainAuthState;
use App\Models\OutboundDomainAuthentication;
use InvalidArgumentException;

/**
 * Single source of truth for "which outbound provider identities exist,
 * which one is primary/secondary right now, and is a given provider ready".
 *
 * Centralizes what used to be scattered `in_array($provider, ['generic',
 * 'ses'], true)` checks across the transport manager, domain-auth service,
 * and launch readiness/config validators. Nothing here sends mail or
 * mutates anything — it is a pure read/factory layer.
 *
 * Only one *live* transport adapter exists today (see
 * {@see OutboundTransportManager}); "primary" and "secondary" are safe,
 * distinct *identities* used for correlation, webhook routing, domain
 * verification, and manual-retry authorization. They do not imply two
 * independently configured live sending credentials — see
 * docs/OUTBOUND_PROVIDER_PORTABILITY.md for the explicit limitation.
 */
final class OutboundProviderRegistry
{
    /**
     * @var list<string>
     */
    public const SUPPORTED_PROVIDERS = ['generic', 'ses'];

    public function __construct(
        private readonly OutboundTransportManager $transports,
        private readonly OutboundProviderEventParserRegistry $parsers,
        private readonly OutboundTransportConfigValidator $transportConfigValidator,
    ) {}

    /**
     * @return list<string>
     */
    public function supportedProviders(): array
    {
        return self::SUPPORTED_PROVIDERS;
    }

    public function isSupported(string $provider): bool
    {
        return in_array($this->normalize($provider), self::SUPPORTED_PROVIDERS, true);
    }

    /**
     * @throws InvalidArgumentException when the provider is not one of the
     *                                  supported, explicitly registered
     *                                  identities. Unsupported providers
     *                                  always fail closed — never treated
     *                                  as a silent no-op or default.
     */
    public function assertSupported(string $provider): void
    {
        if (! $this->isSupported($provider)) {
            throw new InvalidArgumentException('unsupported_provider');
        }
    }

    /**
     * The provider used for normal (non-failover) sending. Falls back to
     * `generic` when the configured value is unsupported — fails closed to
     * the safest, always-registered identity rather than throwing at
     * config-read time from arbitrary call sites.
     */
    public function primaryProvider(): string
    {
        $configured = $this->normalize((string) config('outbound.primary_provider', 'generic'));

        return $this->isSupported($configured) ? $configured : 'generic';
    }

    /**
     * Raw, unvalidated primary provider value for diagnostics (e.g. so a
     * config validator can report "unsupported" instead of the value being
     * silently masked by the fail-closed fallback in {@see primaryProvider()}).
     */
    public function primaryProviderRaw(): string
    {
        return $this->normalize((string) config('outbound.primary_provider', ''));
    }

    /**
     * The optional secondary provider identity. Returns null (not just
     * "unset") whenever the configured value is empty, unsupported, or
     * identical to the primary provider — a secondary provider that fails
     * closed is treated exactly like "no secondary configured" everywhere
     * that matters for safety (failover eligibility, ops readiness).
     */
    public function secondaryProvider(): ?string
    {
        $configured = config('outbound.secondary_provider');
        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        $configured = $this->normalize($configured);
        if (! $this->isSupported($configured)) {
            return null;
        }

        if ($configured === $this->primaryProvider()) {
            return null;
        }

        return $configured;
    }

    /**
     * Raw secondary provider value (unvalidated) for diagnostics.
     */
    public function secondaryProviderRaw(): ?string
    {
        $configured = config('outbound.secondary_provider');
        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        return $this->normalize($configured);
    }

    /**
     * Whether automatic failover is both configured *and* has a usable
     * secondary provider. This flag alone never triggers an automatic
     * cross-provider retry — DeliverOutboundMessageJob does not implement
     * one. It exists only as a defense-in-depth gate that other code paths
     * (currently none automatic) would additionally need to check.
     */
    public function failoverEnabled(): bool
    {
        return (bool) config('outbound.failover_enabled', false) && $this->secondaryProvider() !== null;
    }

    /**
     * @return list<string> safe, sanitized configuration error codes.
     *                      Never includes secrets. Empty when configuration
     *                      is valid (the common case: single provider, no
     *                      secondary configured).
     */
    public function configErrors(): array
    {
        $errors = [];

        $primaryRaw = $this->primaryProviderRaw();
        if ((bool) config('outbound.enabled', false) && $primaryRaw === '') {
            $errors[] = 'primary_provider_required';
        }
        if ($primaryRaw !== '' && ! $this->isSupported($primaryRaw)) {
            $errors[] = 'primary_provider_unsupported';
        }

        $secondaryRaw = $this->secondaryProviderRaw();
        if ($secondaryRaw !== null) {
            if (! $this->isSupported($secondaryRaw)) {
                $errors[] = 'secondary_provider_unsupported';
            } elseif ($secondaryRaw === $this->primaryProvider()) {
                $errors[] = 'secondary_provider_duplicates_primary';
            }
        }

        if ((bool) config('outbound.failover_enabled', false) && $this->secondaryProvider() === null) {
            $errors[] = 'failover_enabled_without_usable_secondary';
        }

        return $errors;
    }

    /**
     * Resolves a transport tagged with the given provider's identity.
     * Only one live transport driver exists (config('outbound.transport'));
     * this tags the result with the requested provider identity rather than
     * re-deriving it from global config, so callers acting on behalf of a
     * specific provider (e.g. a manual provider retry) get a correctly
     * labeled result even though the underlying wire transport is shared.
     */
    public function resolveTransport(string $provider): OutboundTransportInterface
    {
        $this->assertSupported($provider);

        return $this->transports->resolve($this->normalize($provider));
    }

    public function resolveParser(string $provider): OutboundProviderEventParserInterface
    {
        $this->assertSupported($provider);

        return $this->parsers->for($this->normalize($provider));
    }

    /**
     * Safe, static capability flags for a provider. Never reflects live
     * credential state (see {@see readiness()} for that) and never includes
     * secrets.
     *
     * @return array{
     *     provider: string,
     *     smtp_submission: bool,
     *     delivery_webhooks: bool,
     *     bounce_events: bool,
     *     complaint_events: bool,
     *     safe_connectivity_probe: bool,
     *     provider_message_id: bool,
     * }
     */
    public function capabilities(string $provider): array
    {
        $this->assertSupported($provider);
        $provider = $this->normalize($provider);

        $webhookRegistered = array_key_exists($provider, (array) config('outbound.delivery_webhook.providers', []));

        return [
            'provider' => $provider,
            'smtp_submission' => true,
            'delivery_webhooks' => $webhookRegistered,
            'bounce_events' => $webhookRegistered,
            'complaint_events' => $webhookRegistered,
            // No live connectivity probe is implemented for any provider
            // today (Scope: no new live adapter). Always false until a
            // real, safe (non-sending) probe exists.
            'safe_connectivity_probe' => false,
            'provider_message_id' => true,
        ];
    }

    /**
     * Secret-free readiness for a single provider identity. Combines
     * parser resolution, webhook secret presence, the (shared) transport
     * validation, and provider-scoped domain-verification coverage.
     *
     * @return array<string, mixed>
     */
    public function readiness(string $provider): array
    {
        $normalized = $this->normalize($provider);

        if (! $this->isSupported($normalized)) {
            return [
                'provider' => $normalized,
                'supported' => false,
                'is_primary' => false,
                'is_secondary' => false,
                'ready' => false,
                'failure_code' => 'unsupported_provider',
            ];
        }

        $parserResolves = true;
        try {
            $this->parsers->for($normalized);
        } catch (\Throwable) {
            $parserResolves = false;
        }

        $webhookSecretPresent = $normalized === 'ses'
            ? trim((string) config('outbound.delivery_webhook.providers.ses.topic_arn', '')) !== ''
            : trim((string) config("outbound.delivery_webhook.providers.{$normalized}.secret", '')) !== '';

        $transportValidation = $this->transportConfigValidator->validate();
        $domainVerifiedCount = OutboundDomainAuthentication::query()
            ->where('provider', $normalized)
            ->where('state', OutboundDomainAuthState::Verified->value)
            ->count();

        $ready = $parserResolves && $webhookSecretPresent && $transportValidation['valid'];

        return [
            'provider' => $normalized,
            'supported' => true,
            'is_primary' => $normalized === $this->primaryProvider(),
            'is_secondary' => $normalized === $this->secondaryProvider(),
            'parser_resolves' => $parserResolves,
            'webhook_secret_present' => $webhookSecretPresent,
            'transport_valid' => $transportValidation['valid'],
            'domain_verified_count' => $domainVerifiedCount,
            'ready' => $ready,
            'failure_code' => $ready ? null : 'provider_not_ready',
        ];
    }

    private function normalize(string $provider): string
    {
        return strtolower(trim($provider));
    }
}
