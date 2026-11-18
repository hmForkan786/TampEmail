<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\ApiKey\ApiKeyResolver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate Bearer API keys and fail closed for inactive principals.
 *
 * Soft-deleted or missing owners → 401 (credential has no valid principal).
 * Suspended, banned, or pending owners → 403 (credential known but principal blocked).
 * This applies to every `api.key` route, including billing paths that omit `api.scope`.
 */
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

        $owner = $apiKey->user()->first();
        if (! $owner instanceof User) {
            return $this->unauthenticated();
        }

        if ($owner->status !== UserStatus::Active) {
            return $this->forbidden();
        }

        $apiKey->setRelation('user', $owner);
        $request->attributes->set('apiKey', $apiKey);
        $request->attributes->set('apiKeyOwner', $owner);

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

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'forbidden',
                'message' => 'You do not have permission to perform this action.',
            ],
        ], 403);
    }
}
