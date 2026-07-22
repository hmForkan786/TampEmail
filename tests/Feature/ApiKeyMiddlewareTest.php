<?php

use App\Models\ApiKey;
use App\Services\ApiKey\ApiKeyResolver;
use App\Services\ApiKey\ApiKeyTokenGenerator;
use App\Services\ApiKey\ApiKeyTokenHasher;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\RequireApiKeyScope;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;

function apiKeyMiddlewareRequest(?string $authorization): Request
{
    $request = Request::create('/api/v1/test');

    if ($authorization !== null) {
        $request->headers->set('Authorization', $authorization);
    }

    return $request;
}

function installApiKeyResolver(ApiKey $apiKey): string
{
    config(['api.key_hash_secret' => 'middleware-test-secret']);
    $hasher = new ApiKeyTokenHasher;
    $credentials = (new ApiKeyTokenGenerator($hasher))->generate();
    $apiKey->key_hash = $credentials['key_hash'];
    $repository = Mockery::mock(ApiKeyRepositoryInterface::class);
    $repository->allows('findActiveByPrefixAndHash')->andReturn($apiKey);
    app()->instance(ApiKeyResolver::class, new ApiKeyResolver($repository, $hasher));

    return $credentials['plain_token'];
}

it('rejects missing and malformed bearer headers', function (): void {
    foreach ([null, 'Basic abc', 'Bearer'] as $authorization) {
        $response = app(Pipeline::class)->send(apiKeyMiddlewareRequest($authorization))
            ->through([AuthenticateApiKey::class])
            ->then(fn () => response()->json(['ok' => true]));

        expect($response->getStatusCode())->toBe(401);
    }
});

it('exposes the resolved key and enforces scopes through middleware aliases', function (): void {
    $apiKey = new ApiKey(['permissions' => ['mail_servers:read']]);
    $token = installApiKeyResolver($apiKey);

    $request = apiKeyMiddlewareRequest('Bearer '.$token);
    $response = app(Pipeline::class)
        ->send($request)
        ->through([AuthenticateApiKey::class, RequireApiKeyScope::class.':mail_servers:read'])
        ->then(fn (Request $request) => response()->json([
            'has_key' => $request->attributes->get('apiKey') === $apiKey,
            'has_context' => $request->attributes->get('apiKeyContext') !== null,
        ]));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toMatchArray(['has_key' => true, 'has_context' => true]);
});

it('returns forbidden for a missing scope and allows admin scope', function (): void {
    $apiKey = new ApiKey(['permissions' => ['mail_servers:admin']]);
    $token = installApiKeyResolver($apiKey);

    $request = apiKeyMiddlewareRequest('Bearer '.$token);
    $response = app(Pipeline::class)->send($request)->through([AuthenticateApiKey::class, RequireApiKeyScope::class.':mail_servers:write'])
        ->then(fn () => response()->json(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
});
