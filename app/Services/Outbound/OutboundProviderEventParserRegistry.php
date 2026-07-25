<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Contracts\OutboundProviderEventParserInterface;
use InvalidArgumentException;

final class OutboundProviderEventParserRegistry
{
    /**
     * @param  iterable<OutboundProviderEventParserInterface>  $parsers
     */
    public function __construct(
        private readonly iterable $parsers,
    ) {}

    public function for(string $provider): OutboundProviderEventParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($provider)) {
                return $parser;
            }
        }

        throw new InvalidArgumentException('unsupported_provider');
    }
}
