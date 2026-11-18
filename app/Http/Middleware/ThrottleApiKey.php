<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ApiKey\AuthenticatedApiKeyContext;
use App\Services\Audit\AuditLogWriter;
use App\Services\Commercial\CommercialResponseFactory;
use App\Services\Entitlement\EntitlementService;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleApiKey
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly EntitlementService $entitlements,
        private readonly AuditLogWriter $audit,
        private readonly CommercialResponseFactory $responses,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->attributes->get('apiKeyContext');
        $key = $this->key($request, $context);
        $limit = $this->limit($request, $context);
        $decay = 60;

        if ($limit < 1 || $this->limiter->tooManyAttempts($key, max(1, $limit))) {
            $retryAfter = $limit < 1 ? 60 : max(1, $this->limiter->availableIn($key));
            $owner = $context instanceof AuthenticatedApiKeyContext ? $context->owner : $request->attributes->get('apiKeyOwner');
            if ($owner instanceof User) {
                $this->audit->write('commercial.api_rate_limited', (string) $owner->getKey(), null, null, null, [
                    'feature' => 'api.max_requests_per_minute',
                    'limit' => $limit,
                    'remaining' => 0,
                ]);
            }

            return $this->response(
                $request,
                $this->responses->rateLimitExceeded(
                    'api.max_requests_per_minute',
                    max(0, $limit),
                    0,
                    $owner instanceof User ? $owner : null,
                ),
                max(0, $limit),
                0,
                $retryAfter,
            );
        }

        $this->limiter->hit($key, $decay);
        $response = $next($request);
        $remaining = max(0, $limit - $this->limiter->attempts($key));
        $retryAfter = $this->limiter->availableIn($key);

        return $this->response($request, $response, $limit, $remaining, $retryAfter);
    }

    private function key(Request $request, mixed $context): string
    {
        if ($context instanceof AuthenticatedApiKeyContext) {
            return 'api-key:'.$context->id();
        }

        $owner = $request->attributes->get('apiKeyOwner');
        if ($owner !== null) {
            return 'api-user:'.$owner->getKey();
        }

        return 'api-ip:'.$request->ip();
    }

    private function limit(Request $request, mixed $context): int
    {
        $fallback = max(0, (int) config('abuse.rate_limits.api_per_minute', 60));
        if (! $context instanceof AuthenticatedApiKeyContext) {
            return $fallback;
        }

        $commercial = $this->entitlements->limit($context->owner, 'api.max_requests_per_minute');
        $configured = $context->apiKey->rate_limit_per_minute;
        $keyLimit = $configured > 0 ? $configured : $fallback;

        return min($fallback, $commercial, $keyLimit);
    }

    private function response(Request $request, Response $response, int $limit, int $remaining, int $retryAfter): Response
    {
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
        if ($response->getStatusCode() === 429) {
            $response->headers->set('Retry-After', (string) max(1, $retryAfter));
        }

        return $response;
    }
}
