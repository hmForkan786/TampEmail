<?php

declare(strict_types=1);

namespace App\Services\Billing\Payload;

use App\Contracts\Billing\ProviderPayloadParser;
use LogicException;

final class ProviderPayloadParserRegistry
{
    /** @var list<ProviderPayloadParser> */
    private array $parsers;

    /** @param iterable<ProviderPayloadParser> $parsers */
    public function __construct(iterable $parsers)
    {
        $this->parsers = array_values(is_array($parsers) ? $parsers : iterator_to_array($parsers));
        $seen = [];
        foreach ($this->parsers as $parser) {
            $key = $parser->provider().':'.get_class($parser);
            if (isset($seen[$key])) {
                throw new LogicException('Duplicate provider payload parser.');
            }
            $seen[$key] = true;
        }
    }

    public function resolve(string $provider, string $contentType): ?ProviderPayloadParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->provider() === strtolower($provider) && $parser->supports($contentType)) {
                return $parser;
            }
        }
        foreach ($this->parsers as $parser) {
            if ($parser->provider() === '*' && $parser->supports($contentType)) {
                return $parser;
            }
        }

        return null;
    }
}
