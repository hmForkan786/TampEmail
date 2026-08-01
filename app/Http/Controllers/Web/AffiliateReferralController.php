<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Affiliates\AffiliateAttributionService;
use App\Services\Affiliates\AffiliateReferralRedirectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Public referral-link entry point (`/r/{affiliateCode}`). Records the
 * click, (re)issues the visitor cookie, and redirects to a safe internal
 * destination. Never redirects off-platform: an unrecognized or
 * non-allow-listed `to` query value silently falls back to the default path.
 */
final class AffiliateReferralController extends Controller
{
    public function __construct(
        private readonly AffiliateAttributionService $attribution,
        private readonly AffiliateReferralRedirectService $redirects,
    ) {}

    public function __invoke(string $affiliateCode, Request $request): RedirectResponse
    {
        $cookieName = (string) config('affiliates.cookie.name');
        $result = $this->attribution->recordClick($affiliateCode, $request, $request->cookie($cookieName));

        $destination = $this->redirects->resolveDestination($request->query('to'));
        $response = redirect()->to($destination);

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
