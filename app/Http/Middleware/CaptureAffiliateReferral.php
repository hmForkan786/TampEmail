<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Affiliates\AffiliateAttributionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records a referral click whenever an inbound web request carries a `ref`
 * query parameter, and (re)issues the affiliate visitor cookie. Applied
 * broadly to the web middleware group, but only ever does work when a
 * `ref` parameter is actually present, so it is a no-op for every other
 * request.
 */
final class CaptureAffiliateReferral
{
    public function __construct(private readonly AffiliateAttributionService $attribution) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('affiliates.enabled') !== true) {
            return $next($request);
        }

        $affiliateCode = $request->query('ref');

        if (! is_string($affiliateCode) || trim($affiliateCode) === '') {
            return $next($request);
        }

        $cookieName = (string) config('affiliates.cookie.name');
        $result = $this->attribution->recordClick($affiliateCode, $request, $request->cookie($cookieName));

        /** @var Response $response */
        $response = $next($request);

        if ($result['cookie_should_set'] === true && is_string($result['visitor_token'])) {
            $response->headers->setCookie($this->buildCookie($cookieName, $result['visitor_token']));
        }

        return $response;
    }

    private function buildCookie(string $name, string $value): Cookie
    {
        $days = (int) config('affiliates.cookie.days', 30);

        return new Cookie(
            $name,
            $value,
            now()->addDays($days)->getTimestamp(),
            '/',
            null,
            (bool) config('affiliates.cookie.secure', true),
            (bool) config('affiliates.cookie.http_only', true),
            false,
            (string) config('affiliates.cookie.same_site', 'lax'),
        );
    }
}
