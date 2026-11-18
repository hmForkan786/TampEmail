<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Contracts\Ads\AdProvider;
use App\Enums\AdProviderName;
use App\Exceptions\Ads\UnknownAdProviderException;
use Illuminate\Contracts\Container\Container;

final class AdProviderRegistry
{
    /** @var array<string, AdProvider> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    public function get(string $provider): AdProvider
    {
        $slug = AdProviderName::normalize($provider);

        if (isset($this->resolved[$slug])) {
            return $this->resolved[$slug];
        }

        $class = config("ads.providers.{$slug}");
        if (! is_string($class) || $class === '') {
            throw new UnknownAdProviderException("No ad provider registered for [{$slug}].");
        }

        $adapter = $this->container->make($class);
        if (! $adapter instanceof AdProvider) {
            throw new UnknownAdProviderException("Provider [{$class}] does not implement AdProvider.");
        }

        return $this->resolved[$slug] = $adapter;
    }

    public function has(string $provider): bool
    {
        $slug = AdProviderName::normalize($provider);

        return is_string(config("ads.providers.{$slug}")) && config("ads.providers.{$slug}") !== '';
    }

    /** @return list<string> */
    public function registeredProviders(): array
    {
        return array_keys((array) config('ads.providers', []));
    }

    /** @return list<string> */
    public function availableProviders(): array
    {
        $available = [];
        foreach ($this->registeredProviders() as $slug) {
            try {
                if ($this->get($slug)->isAvailable()) {
                    $available[] = $slug;
                }
            } catch (UnknownAdProviderException) {
                continue;
            }
        }

        return $available;
    }
}
