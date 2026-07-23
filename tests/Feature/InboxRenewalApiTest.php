<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function renewalFixture(?User $owner = null, array $inbox = []): array
{
    $owner ??= User::factory()->create(['platform_role' => PlatformRole::User]);
    $domain = Domain::create(['domain' => 'renew-'.bin2hex(random_bytes(3)).'.test', 'display_name' => 'Renew', 'is_active' => true, 'is_public' => true, 'allow_registration' => true, 'is_healthy' => true, 'retention_hours' => 24]);
    $record = Inbox::create(array_merge(['domain_id' => $domain->id, 'user_id' => $owner->id, 'local_part' => 'renew', 'full_address' => 'renew-'.bin2hex(random_bytes(3)).'@'.$domain->domain, 'inbox_type' => 'temporary', 'expires_at' => now()->addHour(), 'is_active' => true], $inbox));
    $issued = app(CreateApiKeyAction::class)->issue(userId: $owner->id, name: 'renew-key', permissions: ['inboxes:read', 'inboxes:write'], user: $owner);
    return [$owner, $domain, $record, $issued->plainToken, $issued->apiKey];
}

beforeEach(function (): void { config(['api.key_hash_secret' => 'renewal-test-secret', 'inbox_lifetime.renewal_enabled' => true, 'inbox_lifetime.max_extension_hours_per_request' => 24, 'inbox_lifetime.max_absolute_lifetime_hours' => 720]); });

it('renews an owned inbox and writes one safe audit record', function (): void {
    [$owner, $domain, $inbox, $token, $key] = renewalFixture();
    $target = now()->addHours(2);
    $response = $this->withToken($token)->patchJson('/api/v1/inboxes/'.$inbox->id.'/expiration', ['expires_at' => $target->toIso8601String()]);
    $response->assertOk()->assertJsonPath('data.id', $inbox->id);
    $audit = AuditLog::query()->where('action', 'inbox.expiration_extended')->sole();
    expect($audit->user_id)->toBe($owner->id)->and($audit->metadata)->toMatchArray(['source' => 'api', 'api_key_id' => $key->id])
        ->and($audit->metadata)->not->toHaveKey('address')->and($audit->metadata)->not->toHaveKey('mail_server_id');
});

it('fails closed when disabled or scope is missing', function (): void {
    [$owner, $domain, $inbox, $token] = renewalFixture();
    config(['inbox_lifetime.renewal_enabled' => false]);
    $this->withToken($token)->patchJson('/api/v1/inboxes/'.$inbox->id.'/expiration', ['expires_at' => now()->addHours(2)])->assertStatus(403);
    [, , $other, $readToken] = renewalFixture();
    $this->withToken($readToken)->patchJson('/api/v1/inboxes/'.$other->id.'/expiration', ['expires_at' => now()->addHours(2)])->assertStatus(403);
});

it('rejects foreign anonymous expired inactive deleted and invalid renewal requests', function (): void {
    [$owner, $domain, $inbox, $token] = renewalFixture();
    [, , $foreign] = renewalFixture();
    $this->withToken($token)->patchJson('/api/v1/inboxes/'.$foreign->id.'/expiration', ['expires_at' => now()->addHours(2)])->assertNotFound();
    $expired = renewalFixture($owner, ['expires_at' => now()->subMinute()])[2];
    $this->withToken($token)->patchJson('/api/v1/inboxes/'.$expired->id.'/expiration', ['expires_at' => now()->addHours(2)])->assertNotFound();
    $inactive = renewalFixture($owner, ['is_active' => false])[2];
    $this->withToken($token)->patchJson('/api/v1/inboxes/'.$inactive->id.'/expiration', ['expires_at' => now()->addHours(2)])->assertNotFound();
    $deleted = renewalFixture($owner)[2]; $deleted->delete();
    $this->withToken($token)->patchJson('/api/v1/inboxes/'.$deleted->id.'/expiration', ['expires_at' => now()->addHours(2)])->assertNotFound();
    $this->withToken($token)->patchJson('/api/v1/inboxes/'.$inbox->id.'/expiration', ['expires_at' => now()->subMinute()])->assertStatus(422);
    $this->withToken($token)->patchJson('/api/v1/inboxes/'.$inbox->id.'/expiration', [])->assertStatus(422);
});

it('rejects shortening, unchanged and over-limit expirations without audit', function (): void {
    [, , $inbox, $token] = renewalFixture();
    $url = '/api/v1/inboxes/'.$inbox->id.'/expiration';
    $this->withToken($token)->patchJson($url, ['expires_at' => $inbox->expires_at->toIso8601String()])->assertStatus(422);
    $this->withToken($token)->patchJson($url, ['expires_at' => now()->addDays(2)->toIso8601String()])->assertStatus(422);
    expect(AuditLog::query()->where('action', 'inbox.expiration_extended')->count())->toBe(0);
});

it('rolls back expiry when audit writing fails', function (): void {
    [, , $inbox, $token] = renewalFixture();
    $old = $inbox->expires_at;
    $audit = Mockery::mock(\App\Services\Audit\AuditLogWriter::class);
    $audit->shouldReceive('write')->once()->andThrow(new RuntimeException('audit unavailable'));
    app()->instance(\App\Services\Audit\AuditLogWriter::class, $audit);
    $this->withToken($token)->patchJson('/api/v1/inboxes/'.$inbox->id.'/expiration', ['expires_at' => now()->addHours(2)])->assertStatus(500);
    expect($inbox->fresh()->expires_at->equalTo($old))->toBeTrue();
});
