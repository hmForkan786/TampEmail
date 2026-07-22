<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Enums\PlatformRole;
use App\Models\ApiKey;
use App\Models\MailServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function issueMailServerApiKey(array $scopes = ['mail_servers:read']): array
{
    $role = in_array('mail_servers:admin', $scopes, true)
        ? PlatformRole::Admin
        : PlatformRole::Operator;

    $user = User::factory()->create(['platform_role' => $role]);
    $issued = app(CreateApiKeyAction::class)->issue(
        userId: $user->id,
        name: 'mail-server-test',
        permissions: $scopes,
        user: $user,
    );

    return [$user, $issued->plainToken, $issued->apiKey];
}

function mailServerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Primary inbound',
        'hostname' => 'mail.example.test',
        'provider' => 'smtp',
        'protocol' => 'smtp',
        'pool_key' => 'standard',
        'max_inboxes' => 25,
    ], $overrides);
}

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'feature-test-api-key-secret']);
});

it('rejects missing, malformed, invalid, revoked and expired credentials', function (): void {
    $missing = $this->getJson('/api/v1/mail-servers');
    expect($missing->status())->toBe(401)
        ->and($missing->json('error.code'))->toBe('unauthenticated');

    $malformed = $this->withHeader('Authorization', 'Basic invalid')->getJson('/api/v1/mail-servers');
    expect($malformed->status())->toBe(401)
        ->and($malformed->json('error.code'))->toBe('unauthenticated');

    $invalid = $this->withHeader('Authorization', 'Bearer te_live_invalid')->getJson('/api/v1/mail-servers');
    expect($invalid->status())->toBe(401)
        ->and($invalid->json('error.code'))->toBe('unauthenticated');

    [, $revokedToken, $revokedKey] = issueMailServerApiKey();
    $revokedKey->update(['revoked_at' => now()]);
    $revoked = $this->withToken($revokedToken)->getJson('/api/v1/mail-servers');
    expect($revoked->status())->toBe(401)
        ->and($revoked->json('error.code'))->toBe('unauthenticated')
        ->and(json_encode($revoked->json()))->not->toContain($revokedToken);

    [, $expiredToken, $expiredKey] = issueMailServerApiKey();
    $expiredKey->update(['expires_at' => now()->subMinute()]);
    $expired = $this->withToken($expiredToken)->getJson('/api/v1/mail-servers');
    expect($expired->status())->toBe(401)
        ->and($expired->json('error.code'))->toBe('unauthenticated')
        ->and(json_encode($expired->json()))->not->toContain($expiredToken);
});

it('enforces read and write scopes while allowing admin scope', function (): void {
    [, $writeToken] = issueMailServerApiKey(['mail_servers:write']);
    $forbiddenRead = $this->withToken($writeToken)->getJson('/api/v1/mail-servers');
    expect($forbiddenRead->status())->toBe(403)
        ->and($forbiddenRead->json('error.code'))->toBe('forbidden');

    [, $readToken] = issueMailServerApiKey(['mail_servers:read']);
    $forbiddenWrite = $this->withToken($readToken)->postJson('/api/v1/mail-servers', mailServerPayload());
    expect($forbiddenWrite->status())->toBe(403)
        ->and($forbiddenWrite->json('error.code'))->toBe('forbidden');

    [, $adminToken] = issueMailServerApiKey(['mail_servers:admin']);
    expect($this->withToken($adminToken)->postJson('/api/v1/mail-servers', mailServerPayload())->status())->toBe(201);
});

it('lists and shows persisted mail servers with pagination and nullable capacity', function (): void {
    [, $token] = issueMailServerApiKey();
    $server = MailServer::create(mailServerPayload(['max_inboxes' => null]));

    $list = $this->withToken($token)->getJson('/api/v1/mail-servers?per_page=1');
    $list->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ])
        ->assertJsonMissingPath('links');
    expect($list->json('data.0.pool_key'))->toBe('standard')
        ->and($list->json('data.0.max_inboxes'))->toBeNull()
        ->and($list->json('meta.per_page'))->toBe(1)
        ->and($list->json('meta.current_page'))->toBe(1)
        ->and($list->json('meta.total'))->toBe(1);
    expect($list->json('data.0'))->not->toHaveKey('metadata');

    $this->withToken($token)->getJson('/api/v1/mail-servers/'.$server->id)
        ->assertOk()
        ->assertJsonPath('data.id', $server->id)
        ->assertJsonPath('data.max_inboxes', null)
        ->assertJsonStructure(['data' => ['id', 'pool_key', 'max_inboxes']]);
    expect($this->getJson('/api/v1/mail-servers/'.$server->id)->json('data'))->not->toHaveKey('metadata');

    $missing = $this->withToken($token)->getJson('/api/v1/mail-servers/'.str_repeat('0', 36));
    $missing->assertNotFound()
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
});

it('creates and updates pool and capacity fields through the API', function (): void {
    [, $writeToken] = issueMailServerApiKey(['mail_servers:write']);

    $created = $this->withToken($writeToken)->postJson('/api/v1/mail-servers', mailServerPayload([
        'metadata' => ['password' => 'do-not-return', 'token' => 'secret-token'],
    ]));
    $created->assertCreated()
        ->assertJsonPath('data.pool_key', 'standard')
        ->assertJsonPath('data.max_inboxes', 25)
        ->assertJsonStructure(['data' => ['id', 'pool_key', 'max_inboxes']]);
    expect($created->json('data'))->not->toHaveKey('metadata');
    expect($created->json('data'))->not->toHaveKeys(['poolKey', 'maxInboxes']);
    $id = $created->json('data.id');

    $this->assertDatabaseHas('mail_servers', [
        'id' => $id,
        'pool_key' => 'standard',
        'max_inboxes' => 25,
    ]);

    $this->withToken($writeToken)->patchJson('/api/v1/mail-servers/'.$id, [
        'pool_key' => 'premium',
        'max_inboxes' => 50,
    ])->assertOk()
        ->assertJsonPath('data.pool_key', 'premium')
        ->assertJsonPath('data.max_inboxes', 50)
        ->assertJsonStructure(['data']);
    expect($this->withToken($writeToken)->getJson('/api/v1/mail-servers/'.$id)->json('data'))->not->toHaveKey('metadata');

    $this->assertDatabaseHas('mail_servers', ['id' => $id, 'pool_key' => 'premium', 'max_inboxes' => 50]);

    $updated = $this->withToken($writeToken)->patchJson('/api/v1/mail-servers/'.$id, ['name' => 'Renamed']);
    $updated->assertOk();
    expect($updated->json('data'))->not->toHaveKey('metadata');
    $this->assertDatabaseHas('mail_servers', ['id' => $id, 'pool_key' => 'premium', 'max_inboxes' => 50, 'name' => 'Renamed']);
});

it('returns validation errors and never exposes the plaintext API token', function (): void {
    [, $token] = issueMailServerApiKey(['mail_servers:write']);
    $response = $this->withToken($token)->postJson('/api/v1/mail-servers', mailServerPayload([
        'pool_key' => '   ',
        'max_inboxes' => 0,
    ]));

    $response->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure([
            'error' => [
                'code',
                'message',
                'details',
            ],
        ]);
    expect($response->json('error.details'))->toHaveKey('max_inboxes')
        ->and(json_encode($response->json()))->not->toContain($token)
        ->and($response->json())->not->toHaveKeys(['message', 'errors']);
});
