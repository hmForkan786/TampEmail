<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Contracts\DnsResolverInterface;
use RuntimeException;

/**
 * Production DNS resolver using dns_get_record with bounded timeout via ini.
 */
final class PhpDnsResolver implements DnsResolverInterface
{
    public function lookupTxt(string $name): array
    {
        return $this->lookup($name, DNS_TXT, 'txt');
    }

    public function lookupCname(string $name): array
    {
        return $this->lookup($name, DNS_CNAME, 'target');
    }

    /**
     * @return list<string>
     */
    private function lookup(string $name, int $type, string $field): array
    {
        $name = $this->normalizeName($name);
        if ($name === '') {
            return [];
        }

        $previous = ini_get('default_socket_timeout');
        $timeout = max(1, (int) config('outbound.domain_authentication.dns_timeout_seconds', 3));
        ini_set('default_socket_timeout', (string) $timeout);

        try {
            $records = @dns_get_record($name, $type);
        } catch (\Throwable $exception) {
            throw new RuntimeException('dns_lookup_failed', 0, $exception);
        } finally {
            if (is_string($previous)) {
                ini_set('default_socket_timeout', $previous);
            }
        }

        if ($records === false) {
            throw new RuntimeException('dns_lookup_failed');
        }

        $values = [];
        foreach ($records as $record) {
            if (! is_array($record) || ! isset($record[$field])) {
                continue;
            }
            $value = $record[$field];
            if (is_array($value)) {
                $value = implode('', $value);
            }
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $values[] = $this->normalizeValue($value);
        }

        return array_values(array_unique($values));
    }

    private function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = rtrim($name, '.');

        return preg_replace('/[^a-z0-9._-]/', '', $name) ?? '';
    }

    private function normalizeValue(string $value): string
    {
        $value = trim($value);
        $value = trim($value, '"');
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return rtrim(strtolower($value), '.');
    }
}
