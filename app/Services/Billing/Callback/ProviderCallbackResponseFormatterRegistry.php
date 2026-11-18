<?php

declare(strict_types=1);

namespace App\Services\Billing\Callback;

use App\Contracts\Billing\ProviderCallbackResponseFormatter;

final class ProviderCallbackResponseFormatterRegistry
{
    /** @param iterable<ProviderCallbackResponseFormatter> $formatters */
    public function __construct(private readonly iterable $formatters) {}

    public function resolve(string $provider): ProviderCallbackResponseFormatter
    {
        $fallback = null;
        foreach ($this->formatters as $formatter) {
            if ($formatter->provider() === strtolower($provider)) {
                return $formatter;
            }
            if ($formatter->provider() === '*') {
                $fallback = $formatter;
            }
        }

        return $fallback ?? throw new \LogicException('No callback response formatter registered.');
    }
}
