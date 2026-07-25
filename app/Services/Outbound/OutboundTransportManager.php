<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Contracts\OutboundTransportInterface;

final class OutboundTransportManager
{
    public function resolve(): OutboundTransportInterface
    {
        $driver = (string) config('outbound.transport', 'unavailable');

        return match ($driver) {
            'array' => new LaravelMailOutboundTransport(
                mailer: 'array',
                providerName: 'array',
            ),
            'smtp', 'mail' => new LaravelMailOutboundTransport(
                mailer: (string) config('outbound.mailer', 'smtp'),
                providerName: $driver,
            ),
            'unavailable' => new UnavailableOutboundTransport,
            default => new UnavailableOutboundTransport(
                failureCode: 'invalid_config',
                failureMessage: 'Unknown outbound transport driver.',
            ),
        };
    }
}
