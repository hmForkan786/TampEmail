<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\Billing\PaymentGateway;
use App\Enums\PaymentProviderName;
use App\Exceptions\Billing\UnknownPaymentProviderException;
use Illuminate\Contracts\Container\Container;

final class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    public function get(string $provider): PaymentGateway
    {
        $slug = PaymentProviderName::normalize($provider);

        if (isset($this->resolved[$slug])) {
            return $this->resolved[$slug];
        }

        $class = config("billing.gateways.{$slug}");
        if (! is_string($class) || $class === '') {
            throw new UnknownPaymentProviderException("No gateway registered for provider [{$slug}].");
        }

        $gateway = $this->container->make($class);
        if (! $gateway instanceof PaymentGateway) {
            throw new UnknownPaymentProviderException("Gateway [{$class}] does not implement PaymentGateway.");
        }

        return $this->resolved[$slug] = $gateway;
    }

    /** @return list<string> */
    public function registeredProviders(): array
    {
        return array_keys((array) config('billing.gateways', []));
    }
}
