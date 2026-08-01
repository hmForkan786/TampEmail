<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Exceptions\Ads\UnsafeAdContentException;

/**
 * Fail-closed URL and HTML sanitizer for ad render payloads.
 */
final class AdContentSanitizer
{
    public function assertSafeUrl(?string $url, bool $allowRelative = true): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            throw new UnsafeAdContentException('Empty URL is not allowed.');
        }

        if ($allowRelative && str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            if (preg_match('/[\x00-\x1F<>"\']/', $url) === 1) {
                throw new UnsafeAdContentException('Relative URL contains unsafe characters.');
            }

            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeAdContentException('Invalid absolute URL.');
        }

        $scheme = strtolower($parts['scheme']);
        $requireHttps = (bool) config('ads.html.require_https_urls', true);
        $allowed = $requireHttps ? ['https'] : ['https', 'http'];
        if (! in_array($scheme, $allowed, true)) {
            throw new UnsafeAdContentException("URL scheme [{$scheme}] is not allowed.");
        }

        if (str_contains($url, 'javascript:') || str_contains(strtolower($url), 'data:')) {
            throw new UnsafeAdContentException('Dangerous URL scheme detected.');
        }

        return $url;
    }

    public function sanitizeHtml(string $html): string
    {
        $allowedTags = (array) config('ads.html.allowed_tags', []);
        $tagList = implode('', array_map(
            static fn (string $tag): string => '<'.trim($tag).'>',
            array_filter($allowedTags, static fn ($t): bool => is_string($t) && $t !== ''),
        ));

        $stripped = strip_tags($html, $tagList !== '' ? $tagList : null);
        $stripped = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $stripped) ?? '';
        $stripped = preg_replace('/javascript\s*:/i', '', $stripped) ?? '';
        $stripped = preg_replace('/data\s*:/i', '', $stripped) ?? '';

        return trim($stripped);
    }

    public function assertPublisherId(string $publisherId): string
    {
        $publisherId = trim($publisherId);
        if (preg_match('/^ca-pub-\d{10,20}$/', $publisherId) !== 1) {
            throw new UnsafeAdContentException('Invalid AdSense publisher ID.');
        }

        return $publisherId;
    }

    public function assertSlotId(string $slotId): string
    {
        $slotId = trim($slotId);
        if (preg_match('/^\d{5,20}$/', $slotId) !== 1) {
            throw new UnsafeAdContentException('Invalid AdSense slot ID.');
        }

        return $slotId;
    }
}
