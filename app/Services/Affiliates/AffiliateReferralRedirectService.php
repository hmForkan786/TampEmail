<?php

declare(strict_types=1);

namespace App\Services\Affiliates;

/**
 * Resolves a safe post-referral-click redirect destination, preventing
 * open-redirect abuse via referral links. Only allow-listed relative paths
 * are ever returned; anything else falls back to the configured default.
 */
final class AffiliateReferralRedirectService
{
    public function resolveDestination(?string $path): string
    {
        $default = (string) config('affiliates.redirect.default_path', '/');

        if ($path === null) {
            return $default;
        }

        $trimmed = trim($path);

        if ($trimmed === '' || ! str_starts_with($trimmed, '/') || str_starts_with($trimmed, '//')) {
            return $default;
        }

        if (str_contains($trimmed, '\\') || preg_match('/[\x00-\x1F]/', $trimmed) === 1) {
            return $default;
        }

        if (stripos($trimmed, 'javascript:') !== false) {
            return $default;
        }

        /** @var list<string> $allowed */
        $allowed = (array) config('affiliates.redirect.allowed_paths', ['/']);

        foreach ($allowed as $allowedPath) {
            $allowedPath = trim((string) $allowedPath);

            if ($allowedPath === '') {
                continue;
            }

            if ($allowedPath === '/' || $trimmed === $allowedPath || str_starts_with($trimmed, rtrim($allowedPath, '/').'/')) {
                return $trimmed;
            }
        }

        return $default;
    }
}
