<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['api.key_hash_secret' => 'webhook-endpoint-secret']));

it('allows premium owners to create, read, update, enable, disable, rotate, and delete endpoints', function (): void {
    ['user' => $user, 'token' => $token] = premiumWebhookFixture();

    $create = $this->withToken($token)->postJson('/api/v1/webhooks', webhookPayload(['is_active' => false]));
    $create->assertCreated()->assertJsonPath('data.active', false)->assertJsonStructure(['secret']);
    $secret = $create->json('secret');
    $endpointId = $create->json('data.id');

    $this->withToken($token)->getJson('/api/v1/webhooks')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($token)->getJson("/api/v1/webhooks/{$endpointId}")->assertOk()
        ->assertJsonMissingPath('secret')
        ->assertJsonMissing(['secret' => $secret]);

    $this->withToken($token)->patchJson("/api/v1/webhooks/{$endpointId}", ['name' => 'Updated'])->assertOk()
        ->assertJsonPath('data.name', 'Updated')
        ->assertJsonPath('data.active', false);

    $this->withToken($token)->postJson("/api/v1/webhooks/{$endpointId}/enable")->assertOk()
        ->assertJsonPath('data.active', true);

    $rotate = $this->withToken($token)->postJson("/api/v1/webhooks/{$endpointId}/rotate-secret");
    $rotate->assertOk()->assertJsonStructure(['secret']);
    expect(AuditLog::query()->where('action', 'commercial.webhook_secret_rotated')->exists())->toBeTrue();

    $this->withToken($token)->postJson("/api/v1/webhooks/{$endpointId}/disable")->assertOk()
        ->assertJsonPath('data.active', false);

    $this->withToken($token)->deleteJson("/api/v1/webhooks/{$endpointId}")->assertOk();
});

it('denies free users without webhook access and hides other users endpoints', function (): void {
    seedCommercialCatalogue();
    $freeUser = User::factory()->create();
    grantApiWrite($freeUser);
    $freeToken = issueCommercialApiKey($freeUser, ['outbound_messages:write']);
    $this->withToken($freeToken)->postJson('/api/v1/webhooks', webhookPayload())->assertForbidden()
        ->assertJsonPath('error.details.feature', 'webhook.access');

    ['token' => $ownerToken] = premiumWebhookFixture();
    $other = User::factory()->create();
    $endpoint = WebhookEndpoint::query()->create([
        'user_id' => $other->id,
        'name' => 'Other',
        'url' => 'https://example.com/hooks',
        'events' => ['outbound.message.sent'],
        'is_active' => true,
        'secret_encrypted' => 'secret',
    ]);

    $this->withToken($ownerToken)->getJson("/api/v1/webhooks/{$endpoint->id}")->assertNotFound();
});

it('enforces active endpoint limits and disabled slot semantics', function (): void {
    ['user' => $user, 'token' => $token] = premiumWebhookFixture();
    setWebhookEndpointLimit($user, 1);

    $this->withToken($token)->postJson('/api/v1/webhooks', webhookPayload())->assertCreated();
    $this->withToken($token)->postJson('/api/v1/webhooks', webhookPayload(['name' => 'Second']))->assertStatus(409)
        ->assertJsonPath('error.code', 'plan_limit_reached')
        ->assertJsonPath('error.details.feature', 'webhook.max_endpoints');
    expect(AuditLog::query()->where('action', 'commercial.webhook_endpoint_limit_reached')->exists())->toBeTrue();

    $this->withToken($token)->postJson('/api/v1/webhooks', webhookPayload(['name' => 'Disabled', 'is_active' => false]))->assertCreated();
});

it('documents sqlite test limitation for true parallel endpoint quota concurrency', function (): void {
    expect(config('database.default'))->toBe('sqlite');

    // Production uses row-level locking in WebhookEndpointService; SQLite in-memory
    // tests cannot reliably simulate concurrent transactions across connections.
})->note('Parallel concurrency requires MySQL/PostgreSQL integration coverage.');

it('rechecks endpoint limits when enabling and rejects unsupported events', function (): void {
    ['user' => $user, 'token' => $token] = premiumWebhookFixture();
    setWebhookEndpointLimit($user, 1);

    $active = $this->withToken($token)->postJson('/api/v1/webhooks', webhookPayload())->assertCreated()->json('data.id');
    $disabled = $this->withToken($token)->postJson('/api/v1/webhooks', webhookPayload(['name' => 'Disabled', 'is_active' => false]))->assertCreated()->json('data.id');

    $this->withToken($token)->postJson("/api/v1/webhooks/{$disabled}/enable")->assertStatus(409);
    $this->withToken($token)->postJson('/api/v1/webhooks', webhookPayload(['events' => ['not.real']]))->assertStatus(422);
    $this->withToken($token)->postJson("/api/v1/webhooks/{$active}/disable")->assertOk();
    $this->withToken($token)->postJson("/api/v1/webhooks/{$disabled}/enable")->assertOk();
});
