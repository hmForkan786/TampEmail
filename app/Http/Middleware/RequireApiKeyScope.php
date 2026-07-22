<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiKeyScopeNotAllowedException;
use App\Exceptions\InvalidApiKeyScopeException;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKey\ApiKeyScopeRegistry;
use App\Services\ApiKey\AuthenticatedApiKeyContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce a required API-key scope after live owner capability re-check.
 *
 * Decision (Prompt 323):
 * - Missing or soft-deleted owner → 401 unauthenticated (credential has no valid principal).
 * - Valid key/owner but stored scopes unauthorized, unknown, or requested scope missing → 403 forbidden.
 */
final class RequireApiKeyScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $apiKey = $request->attributes->get('apiKey');

        if (! $apiKey instanceof ApiKey) {
            return $this->unauthenticated();
        }

        $owner = $this->resolveOwner($apiKey);

        if ($owner === null) {
            return $this->unauthenticated();
        }

        try {
            ApiKeyScopeRegistry::validateStoredScopesForOwner($apiKey, $owner);
        } catch (InvalidApiKeyScopeException|ApiKeyScopeNotAllowedException) {
            return $this->forbidden();
        }

        $context = new AuthenticatedApiKeyContext($apiKey, $owner);

        if (! $context->allows($scope)) {
            return $this->forbidden();
        }

        $request->attributes->set('apiKeyContext', $context);
        $request->attributes->set('apiKeyOwner', $owner);

        return $next($request);
    }

    private function resolveOwner(ApiKey $apiKey): ?User
    {
        if ($apiKey->relationLoaded('user')) {
            $owner = $apiKey->user;

            return $owner instanceof User ? $owner : null;
        }

        if ($apiKey->user_id === null || $apiKey->user_id === '') {
            return null;
        }

        $owner = $apiKey->user()->first();

        if ($owner instanceof User) {
            $apiKey->setRelation('user', $owner);
        }

        return $owner instanceof User ? $owner : null;
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
