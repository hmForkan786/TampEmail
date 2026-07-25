<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Contracts\OutboundTransportInterface;

final class OutboundTransportManager
{
    public function __construct(
        private readonly OutboundTransportConfigValidator $configValidator,
    ) {}

    /**
     * @param  string|null  $provider  Explicit provider identity to tag the
     *                                 transport result with (e.g. when
     *                                 resolving on behalf of a specific
     *                                 provider via {@see OutboundProviderRegistry}).
     *                                 Defaults to the configured primary
     *                                 provider identity when omitted, so the
     *                                 default binding in bootstrap/app.php
     *                                 keeps working unmodified.
     */
    public function resolve(?string $provider = null): OutboundTransportInterface
    {
        $driver = strtolower(trim((string) config('outbound.transport', 'unavailable')));

        return match ($driver) {
            'array' => new LaravelMailOutboundTransport(
                mailer: 'array',
                providerName: $provider ?? $this->providerIdentity('array'),
            ),
            'smtp', 'mail' => $this->resolveProductionMailer($driver, $provider),
            'unavailable' => new UnavailableOutboundTransport,
            default => new UnavailableOutboundTransport(
                failureCode: 'invalid_config',
                failureMessage: 'Unknown outbound transport driver.',
                configurationFailure: true,
            ),
        };
    }

    private function resolveProductionMailer(string $driver, ?string $provider = null): OutboundTransportInterface
    {
        $validation = $this->configValidator->validate($driver);
        if (! $validation['valid']) {
            return new UnavailableOutboundTransport(
                failureCode: $validation['failure_code'] ?? 'invalid_config',
                failureMessage: 'Outbound transport configuration is invalid.',
                configurationFailure: true,
            );
        }

        $mailer = (string) ($validation['mailer'] ?? config('outbound.mailer', 'outbound'));

        return new LaravelMailOutboundTransport(
            mailer: $mailer,
            providerName: $provider ?? $this->providerIdentity($driver),
        );
    }

    /**
     * Prefer configured vendor identity (e.g. ses) when correlating provider events.
     */
    private function providerIdentity(string $transportDriver): string
    {
        $configured = strtolower(trim((string) config('outbound.provider', 'generic')));
        $supported = ['generic', 'ses'];

        if (! in_array($configured, $supported, true)) {
            return $transportDriver;
        }

        if ($configured === 'ses') {
            return 'ses';
        }

        return $transportDriver;
    }
}
