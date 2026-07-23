<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Inbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'email-read-state-test-secret']);
});

function emailReadStateToken(User $owner, array $scopes = ['inboxes:write']): string
{
    return app(CreateApiKeyAction::class)->issue(
        userId: $owner->id,
        name: 'email-read-state-key',
        permissions: $scopes,
        user: $owner,
    )->plainToken;
}

function emailReadStateFixture(User $owner, array $inboxOverrides = []): array
{
    $domain = Domain::query()->create([
        'domain' => 'read-'.bin2hex(random_bytes(3)).'.test', 'display_name' => 'Read state',
        'is_active' => true, 'is_public' => true, 'allow_registration' => true, 'is_healthy' => true,
        'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create(array_merge([
        'domain_id' => $domain->id, 'user_id' => $owner->id, 'local_part' => 'read',
        'full_address' => 'read@'.$domain->domain, 'inbox_type' => 'temporary', 'is_active' => true,
    ], $inboxOverrides));
    $email = Email::query()->create([
        'inbox_id' => $inbox->id, 'message_id' => 'read-'.bin2hex(random_bytes(3)),
        'sender_email' => 'sender@example.test', 'recipient_email' => $inbox->full_address,
        'subject' => 'Read state', 'received_at' => now(), 'size_bytes' => 1,
        'processing_status' => 'stored', 'is_read' => false, 'read_at' => null,
    ]);

    return compact('inbox', 'email');
}

function emailReadStateUrl(array $fixture, string $state): string
{
    return '/api/v1/inboxes/'.$fixture['inbox']->id.'/emails/'.$fixture['email']->id.'/'.$state;
}

it('lets the owner mark an email read and unread idempotently', function (): void {
    $owner = User::factory()->create();
    $fixture = emailReadStateFixture($owner);
    $token = emailReadStateToken($owner);

    $read = $this->withToken($token)->patchJson(emailReadStateUrl($fixture, 'read'));
    $read->assertOk()->assertJsonPath('data.is_read', true)->assertJsonPath('data.read_at', fn ($value): bool => $value !== null);
    $firstReadAt = $fixture['email']->fresh()->read_at;
    expect($fixture['email']->fresh()->is_read)->toBeTrue()->and($firstReadAt)->not->toBeNull();

    $this->withToken($token)->patchJson(emailReadStateUrl($fixture, 'read'))->assertOk();
    expect($fixture['email']->fresh()->read_at->equalTo($firstReadAt))->toBeTrue();

    $unread = $this->withToken($token)->patchJson(emailReadStateUrl($fixture, 'unread'));
    $unread->assertOk()->assertJsonPath('data.is_read', false)->assertJsonPath('data.read_at', null);
    expect($fixture['email']->fresh()->is_read)->toBeFalse()->and($fixture['email']->fresh()->read_at)->toBeNull();
    $this->withToken($token)->patchJson(emailReadStateUrl($fixture, 'unread'))->assertOk();
    expect($fixture['email']->fresh()->read_at)->toBeNull();
});

it('denies foreign, anonymous, expired, inactive, deleted inbox and deleted email mutations', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $fixture = emailReadStateFixture($owner);
    $url = emailReadStateUrl($fixture, 'read');

    $this->withToken(emailReadStateToken($other))->patchJson($url)->assertNotFound();
    expect($fixture['email']->fresh()->is_read)->toBeFalse();

    foreach ([
        ['expires_at' => now()->subMinute()],
        ['is_active' => false],
    ] as $override) {
        $hidden = emailReadStateFixture($owner, $override);
        $this->withToken(emailReadStateToken($owner))->patchJson(emailReadStateUrl($hidden, 'read'))->assertNotFound();
    }

    $deletedInbox = emailReadStateFixture($owner);
    $deletedInbox['inbox']->delete();
    $this->withToken(emailReadStateToken($owner))->patchJson(emailReadStateUrl($deletedInbox, 'read'))->assertNotFound();

    $deletedEmail = emailReadStateFixture($owner);
    $deletedEmail['email']->delete();
    $this->withToken(emailReadStateToken($owner))->patchJson(emailReadStateUrl($deletedEmail, 'read'))->assertNotFound();
});

it('requires the inboxes write scope and rejects anonymous inboxes', function (): void {
    $owner = User::factory()->create();
    $fixture = emailReadStateFixture($owner);

    $this->withToken(emailReadStateToken($owner, ['inboxes:read']))
        ->patchJson(emailReadStateUrl($fixture, 'read'))->assertForbidden();

    $anonymous = emailReadStateFixture($owner);
    $anonymous['inbox']->update(['user_id' => null]);
    $this->withToken(emailReadStateToken($owner))->patchJson(emailReadStateUrl($anonymous, 'read'))->assertNotFound();
});
