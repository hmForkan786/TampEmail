<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorResponse;
use App\Models\ApiKey;
use App\Services\ApiKey\AuthenticatedApiKeyContext;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ThrottleApiKey
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->attributes->get('apiKeyContext');
        $key = $this->key($request, $context);
        $limit = $this->limit($context);
        $decay = 60;

        if ($this->limiter->tooManyAttempts($key, $limit)) {
            $retryAfter = max(1, $this->limiter->availableIn($key));
            return $this->response($request, ApiErrorResponse::make(
                'rate_limit_exceeded',
                'Too many API requests. Please try again later.',
                429,
            ), $limit, 0, $retryAfter);
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

    private function limit(mixed $context): int
    {
        $configured = $context instanceof AuthenticatedApiKeyContext
            ? $context->apiKey->rate_limit_per_minute
            : null;
        $fallback = (int) config('abuse.rate_limits.api_per_minute', 60);

        return $configured !== null && $configured > 0 ? (int) $configured : max(1, $fallback);
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
