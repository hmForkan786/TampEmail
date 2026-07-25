<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Contracts\OutboundTransportInterface;

final class OutboundTransportManager
{
    public function __construct(
        private readonly OutboundTransportConfigValidator $configValidator,
    ) {}

    public function resolve(): OutboundTransportInterface
    {
        $driver = strtolower(trim((string) config('outbound.transport', 'unavailable')));

        return match ($driver) {
            'array' => new LaravelMailOutboundTransport(
                mailer: 'array',
                providerName: 'array',
            ),
            'smtp', 'mail' => $this->resolveProductionMailer($driver),
            'unavailable' => new UnavailableOutboundTransport,
            default => new UnavailableOutboundTransport(
                failureCode: 'invalid_config',
                failureMessage: 'Unknown outbound transport driver.',
                configurationFailure: true,
            ),
        };
    }

    private function resolveProductionMailer(string $driver): OutboundTransportInterface
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
            providerName: $driver,
        );
    }
}
