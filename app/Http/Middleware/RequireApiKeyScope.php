<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ApiKey\AuthenticatedApiKeyContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireApiKeyScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $apiKey = $request->attributes->get('apiKey');

        if (! $apiKey instanceof \App\Models\ApiKey) {
            return response()->json([
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'Authentication is required.',
                ],
            ], 401);
        }

        $context = new AuthenticatedApiKeyContext($apiKey);

        if (! $context->allows($scope)) {
            return $this->forbidden();
        }

        $request->attributes->set('apiKeyContext', $context);

        return $next($request);
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
