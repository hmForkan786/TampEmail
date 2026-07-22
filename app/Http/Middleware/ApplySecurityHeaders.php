<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplySecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! config('security.headers.enabled')) {
            return $response;
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', config('security.headers.referrer_policy'));
        $response->headers->set('Permissions-Policy', config('security.headers.permissions_policy'));

        if ($request->isSecure() && config('security.headers.hsts_enabled')) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age='.config('security.headers.hsts_max_age').'; includeSubDomains'
            );
        }

        return $response;
    }
}
