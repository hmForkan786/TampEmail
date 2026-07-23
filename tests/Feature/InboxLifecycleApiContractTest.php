<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Enums\PlatformRole;
use App\Models\ApiRequestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void { config(['api.key_hash_secret' => 'contract-matrix-secret']); });

function lifecycleContractToken(array $scopes = ['inboxes:read', 'inboxes:write'], ?User $user = null): array
{
    $user ??= User::factory()->create(['platform_role' => PlatformRole::User]);
    $issued = app(CreateApiKeyAction::class)->issue(userId: $user->id, name: 'contract-key', permissions: $scopes, user: $user);
    RateLimiter::clear('api-key:'.$issued->apiKey->id);
    return [$user, $issued->plainToken, $issued->apiKey];
}

function lifecycleContractEndpoints(): array
{
    $id = '00000000-0000-4000-8000-000000000001';
    $email = '00000000-0000-4000-8000-000000000002';
    $attachment = '00000000-0000-4000-8000-000000000003';
    return [
        'GET /api/v1/inboxes', ['GET', '/api/v1/inboxes'],
        'GET /api/v1/inboxes/{inbox}', ['GET', "/api/v1/inboxes/$id"],
        'POST /api/v1/inboxes', ['POST', '/api/v1/inboxes'],
        'PATCH /api/v1/inboxes/{inbox}/expiration', ['PATCH', "/api/v1/inboxes/$id/expiration"],
        'DELETE /api/v1/inboxes/{inbox}', ['DELETE', "/api/v1/inboxes/$id"],
        'GET /api/v1/inboxes/{inbox}/emails', ['GET', "/api/v1/inboxes/$id/emails"],
        'GET /api/v1/inboxes/{inbox}/emails/{email}', ['GET', "/api/v1/inboxes/$id/emails/$email"],
        'PATCH /api/v1/inboxes/{inbox}/emails/{email}/read', ['PATCH', "/api/v1/inboxes/$id/emails/$email/read"],
        'PATCH /api/v1/inboxes/{inbox}/emails/{email}/unread', ['PATCH', "/api/v1/inboxes/$id/emails/$email/unread"],
        'GET /api/v1/inboxes/{inbox}/emails/{email}/attachments/{attachment}', ['GET', "/api/v1/inboxes/$id/emails/$email/attachments/$attachment"],
    ];
}

it('returns standard 401 envelopes for every lifecycle endpoint without credentials', function (): void {
    foreach (array_chunk(lifecycleContractEndpoints(), 2) as [$label, $request]) {
        [$method, $uri] = $request;
        $response = $method === 'GET' ? $this->getJson($uri) : ($method === 'POST' ? $this->postJson($uri) : ($method === 'DELETE' ? $this->deleteJson($uri) : $this->patchJson($uri)));
        $response->assertStatus(401)->assertJsonStructure(['error' => ['code', 'message']]);
        expect($response->getContent())->not->toContain('00000000-0000-4000-8000-000000000001');
    }
    expect(ApiRequestLog::query()->count())->toBe(10);
});

it('does not let missing scopes reach controllers and returns 403 without consuming the limiter', function (): void {
    [$user, $token, $key] = lifecycleContractToken(['inboxes:write']);
    $before = RateLimiter::attempts('api-key:'.$key->id);
    $response = $this->withToken($token)->getJson('/api/v1/inboxes');
    $response->assertStatus(403)->assertJsonStructure(['error' => ['code', 'message']]);
    expect(RateLimiter::attempts('api-key:'.$key->id))->toBe($before);
    $log = ApiRequestLog::query()->latest('created_at')->first();
    expect($log?->user_id)->toBe($user->id)->and($log?->api_key_id)->toBe($key->id)->and($log?->response_status)->toBe(403);
});

it('returns bounded validation envelopes and hides invalid or foreign resources', function (): void {
    [$owner, $token] = lifecycleContractToken(['inboxes:read', 'inboxes:write']);
    $response = $this->withToken($token)->getJson('/api/v1/inboxes?per_page=not-a-number&sort=secret');
    $response->assertStatus(422)->assertJsonStructure(['error' => ['code', 'message', 'details']]);
    $foreignId = '00000000-0000-4000-8000-000000000099';
    $hidden = $this->withToken($token)->getJson('/api/v1/inboxes/'.$foreignId);
    $hidden->assertStatus(404)->assertJsonStructure(['error' => ['code', 'message']]);
    expect($hidden->getContent())->not->toContain($foreignId)->not->toContain($token)->not->toContain('Authorization');
});
