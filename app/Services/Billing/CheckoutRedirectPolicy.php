<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Exceptions\Billing\CheckoutException;

final class CheckoutRedirectPolicy
{
    public function normalize(string $url, bool $nullable = false): ?string
    {
        $url = trim($url);
        if ($url === '' && $nullable) {
            return null;
        }
        if ($url === '' || str_starts_with($url, '//') || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            $this->reject();
        }

        if (str_starts_with($url, '/')) {
            if (! config('billing.checkout.allow_relative_redirects', true) || str_starts_with($url, '/\\')) {
                $this->reject();
            }

            return '/'.ltrim($url, '/');
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || ! isset($parts['host'])
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->reject();
        }

        $asciiHost = function_exists('idn_to_ascii') ? idn_to_ascii((string) $parts['host']) : (string) $parts['host'];
        $host = strtolower(rtrim($asciiHost ?: (string) $parts['host'], '.'));
        $allowed = array_map(static fn ($value): string => strtolower(rtrim((string) $value, '.')), array_filter(
            (array) config('billing.checkout.allowed_redirect_hosts', [])
        ));
        if (! in_array($host, $allowed, true)) {
            $this->reject();
        }

        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return 'https://'.$host.$port.$path.$query;
    }

    public function assertProviderCheckoutUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new CheckoutException('invalid_provider_response', 'The payment provider returned an invalid checkout session.', 502);
        }

        return $url;
    }

    private function reject(): never
    {
        throw new CheckoutException('invalid_checkout_redirect', 'The checkout redirect is not allowed.', 422);
    }
}
