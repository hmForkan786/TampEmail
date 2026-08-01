<?php

declare(strict_types=1);

namespace App\Services\Webhook;

use App\Exceptions\OutboundSendException;

/** SSRF-resistant webhook URL validation for registration and delivery. */
final class WebhookSecurityValidator
{
    /** @var list<string> */
    private const BLOCKED_HOSTNAMES = [
        'metadata.google.internal',
        'metadata.goog',
    ];

    public function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            throw new OutboundSendException('webhook_url_invalid', 'Webhook URLs must use HTTPS.', 422);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new OutboundSendException('webhook_url_invalid', 'Webhook URLs must not include credentials.', 422);
        }

        $host = strtolower($parts['host']);
        if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            throw new OutboundSendException('webhook_url_unsafe', 'Webhook URL target is not publicly routable.', 422);
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            throw new OutboundSendException('webhook_url_unsafe', 'Webhook URL target is not publicly routable.', 422);
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicRoutableIp($ip)) {
                throw new OutboundSendException('webhook_url_unsafe', 'Webhook URL target is not publicly routable.', 422);
            }
        }
    }

    private function isPublicRoutableIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (str_starts_with($ip, '169.254.') || str_starts_with($ip, '100.64.')) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            if (str_starts_with($lower, 'fe80:') || str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')) {
                return false;
            }
        }

        return true;
    }
}
