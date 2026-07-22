<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ApiKey\ApiKeyResolver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeyResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization');

        if (! is_string($header) || preg_match('/^Bearer ([^ ]+)$/D', $header, $matches) !== 1) {
            return $this->unauthenticated();
        }

        $apiKey = $this->resolver->resolve($matches[1]);

        if ($apiKey === null) {
            return $this->unauthenticated();
        }

        $request->attributes->set('apiKey', $apiKey);

        return $next($request);
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'unauthenticated',
                'message' => 'Authentication is required.',
            ],
        ], 401);
    }
}
