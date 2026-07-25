<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifies Amazon SNS message signatures for SES delivery notifications.
 *
 * Fail-closed: missing fields, unsafe certificate URLs, TLS failures, and
 * unsupported algorithms all reject the payload.
 */
final class SesSnsSignatureVerifier
{
    private const ALLOWED_HOST_PATTERN = '/^sns\.[a-z0-9-]+\.amazonaws\.com$/i';

    private const ALLOWED_HOST_PATTERN_CN = '/^sns\.[a-z0-9-]+\.amazonaws\.com\.cn$/i';

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function verify(array $envelope): bool
    {
        $type = trim((string) ($envelope['Type'] ?? ''));
        $signature = trim((string) ($envelope['Signature'] ?? ''));
        $signatureVersion = trim((string) ($envelope['SignatureVersion'] ?? ''));
        $certUrl = trim((string) ($envelope['SigningCertURL'] ?? $envelope['SigningCertUrl'] ?? ''));
        $timestamp = trim((string) ($envelope['Timestamp'] ?? ''));

        if ($type === '' || $signature === '' || $certUrl === '' || $timestamp === '') {
            return false;
        }

        if (! in_array($signatureVersion, ['1', '2'], true)) {
            return false;
        }

        if (! $this->timestampWithinSkew($timestamp)) {
            return false;
        }

        if (! $this->isAllowedCertificateUrl($certUrl)) {
            return false;
        }

        $certificate = $this->fetchCertificate($certUrl);
        if ($certificate === null) {
            return false;
        }

        $canonical = $this->canonicalString($envelope, $type);
        if ($canonical === null) {
            return false;
        }

        $algorithm = $signatureVersion === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
        $decoded = base64_decode($signature, true);
        if ($decoded === false || $decoded === '') {
            return false;
        }

        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            return false;
        }

        $result = openssl_verify($canonical, $decoded, $publicKey, $algorithm);

        return $result === 1;
    }

    public function isAllowedCertificateUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        if (preg_match(self::ALLOWED_HOST_PATTERN, $host) !== 1
            && preg_match(self::ALLOWED_HOST_PATTERN_CN, $host) !== 1) {
            return false;
        }

        // Certificates are PEM files under a path; reject empty or directory-like paths.
        if ($path === '' || str_ends_with($path, '/') || ! str_ends_with(strtolower($path), '.pem')) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function canonicalString(array $envelope, string $type): ?string
    {
        $fields = match ($type) {
            'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
            'SubscriptionConfirmation', 'UnsubscribeConfirmation' => [
                'Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type',
            ],
            default => null,
        };

        if ($fields === null) {
            return null;
        }

        $canonical = '';
        foreach ($fields as $field) {
            if ($field === 'Subject' && ! array_key_exists('Subject', $envelope)) {
                continue;
            }

            if (! array_key_exists($field, $envelope)) {
                return null;
            }

            $canonical .= $field."\n".((string) $envelope[$field])."\n";
        }

        return $canonical;
    }

    private function timestampWithinSkew(string $timestamp): bool
    {
        try {
            $eventTime = strtotime($timestamp);
        } catch (\Throwable) {
            return false;
        }

        if ($eventTime === false) {
            return false;
        }

        $skew = (int) config('outbound.delivery_webhook.timestamp_skew_seconds', 300);

        return abs(time() - $eventTime) <= max(60, $skew);
    }

    private function fetchCertificate(string $url): ?string
    {
        $cacheKey = 'outbound.ses.sns_cert:'.hash('sha256', $url);
        $ttl = max(60, (int) config('outbound.delivery_webhook.providers.ses.cert_cache_ttl_seconds', 3600));

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'verify' => true,
                'timeout' => 5,
                'connect_timeout' => 3,
            ])->withHeaders([
                'Accept' => 'application/x-pem-file, application/x-x509-ca-cert, text/plain, */*',
            ])->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();
        if ($body === '' || strlen($body) > 65536) {
            return null;
        }

        if (! str_contains($body, 'BEGIN CERTIFICATE')) {
            return null;
        }

        Cache::put($cacheKey, $body, $ttl);

        return $body;
    }
}
