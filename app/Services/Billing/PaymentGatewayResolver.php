<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\Billing\PaymentGateway;
use App\Enums\PaymentCapability;
use App\Enums\PaymentProviderName;
use App\Exceptions\Billing\DisabledPaymentProviderException;
use App\Exceptions\Billing\UnknownPaymentProviderException;

final class PaymentGatewayResolver
{
    public function __construct(private readonly PaymentGatewayRegistry $registry) {}

    public function resolve(?string $provider = null, ?PaymentCapability $capability = null): PaymentGateway
    {
        $slug = PaymentProviderName::normalize($provider ?? (string) config('billing.default_gateway'));
        if (! in_array($slug, $this->registry->registeredProviders(), true)) {
            throw new UnknownPaymentProviderException("Payment provider [{$slug}] is not registered.");
        }

        $enabled = (array) config('billing.enabled_gateways', []);
        if (! in_array($slug, $enabled, true)) {
            throw new DisabledPaymentProviderException("Payment provider [{$slug}] is disabled.");
        }

        $gateway = $this->registry->get($slug);

        if ($capability !== null && ! $gateway->supports($capability)) {
            throw new DisabledPaymentProviderException("Provider [{$slug}] does not support [{$capability->value}].");
        }

        return $gateway;
    }
}
