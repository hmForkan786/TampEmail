<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Contracts\DnsResolverInterface;

/**
 * Deterministic DNS resolver for tests.
 */
final class FakeDnsResolver implements DnsResolverInterface
{
    /**
     * @param  array<string, list<string>>  $txt
     * @param  array<string, list<string>>  $cname
     * @param  list<string>  $failures  hostnames that throw transient failures
     */
    public function __construct(
        private array $txt = [],
        private array $cname = [],
        private array $failures = [],
    ) {}

    /**
     * @param  list<string>  $values
     */
    public function setTxt(string $name, array $values): void
    {
        $this->txt[$this->key($name)] = array_values($values);
    }

    /**
     * @param  list<string>  $values
     */
    public function setCname(string $name, array $values): void
    {
        $this->cname[$this->key($name)] = array_map(
            fn (string $value): string => rtrim(strtolower(trim($value)), '.'),
            $values,
        );
    }

    public function fail(string $name): void
    {
        $this->failures[] = $this->key($name);
    }

    public function lookupTxt(string $name): array
    {
        $key = $this->key($name);
        $this->throwIfFailed($key);

        return $this->txt[$key] ?? [];
    }

    public function lookupCname(string $name): array
    {
        $key = $this->key($name);
        $this->throwIfFailed($key);

        return $this->cname[$key] ?? [];
    }

    private function throwIfFailed(string $key): void
    {
        if (in_array($key, $this->failures, true)) {
            throw new \RuntimeException('dns_lookup_failed');
        }
    }

    private function key(string $name): string
    {
        return rtrim(strtolower(trim($name)), '.');
    }
}
