<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\ApiRequestLog;
use App\Services\ApiKey\AuthenticatedApiKeyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiRequestLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            $this->writeSafely($request, null, $status, $startedAt, false);
            throw $exception;
        }

        $this->writeSafely($request, $response, $response->getStatusCode(), $startedAt, $response->getStatusCode() === 429);

        return $response;
    }

    private function writeSafely(Request $request, ?Response $response, int $status, float $startedAt, bool $throttled): void
    {
        try {
            $context = $request->attributes->get('apiKeyContext');
            $apiKey = $context instanceof AuthenticatedApiKeyContext ? $context->apiKey : $request->attributes->get('apiKey');
            $owner = $context instanceof AuthenticatedApiKeyContext ? $context->owner : $request->attributes->get('apiKeyOwner');
            $apiKey = $apiKey instanceof ApiKey ? $apiKey : null;
            if ($apiKey instanceof ApiKey && $owner === null) {
                $owner = $apiKey->relationLoaded('user') ? $apiKey->user : $apiKey->user()->first();
            }

            ApiRequestLog::query()->create([
                'api_key_id' => $apiKey?->getKey(),
                'user_id' => $owner?->getKey(),
                'method' => strtoupper($request->method()),
                'endpoint' => (string) ($request->route()?->getName() ?? $request->path()),
                'ip_address' => (string) ($request->ip() ?? '0.0.0.0'),
                'user_agent' => $request->userAgent(),
                'response_status' => $status,
                'response_time_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                'request_size_bytes' => $this->requestSize($request),
                'response_size_bytes' => $this->responseSize($response),
                'metadata' => ['was_throttled' => $throttled],
            ]);
        } catch (Throwable $loggingFailure) {
            report($loggingFailure);
        }
    }

    private function requestSize(Request $request): ?int
    {
        $length = $request->headers->get('Content-Length');
        return is_numeric($length) ? (int) $length : null;
    }

    private function responseSize(?Response $response): ?int
    {
        if ($response === null) {
            return null;
        }

        $content = $response->getContent();
        return is_string($content) ? strlen($content) : null;
    }
}
