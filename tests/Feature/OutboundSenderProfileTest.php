<?php

declare(strict_types=1);

use App\Contracts\OutboundTransportInterface;
use App\Exceptions\OutboundSendException;
use App\Models\AuditLog;
use App\Models\Inbox;
use App\Models\OutboundMessage;
use App\Models\OutboundSenderProfile;
use App\Models\User;
use App\Services\Outbound\FakeOutboundTransport;
use App\Services\Outbound\OutboundDraftService;
use App\Services\Outbound\OutboundSenderProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'api.key_hash_secret' => 'outbound-sender-profile-test-secret',
        'outbound.enabled' => true,
        'outbound.send_enabled' => true,
        'outbound.sender_profiles.enabled' => true,
        'outbound.rollout.mode' => 'enabled',
        'outbound.rollout.emergency_stop' => false,
        'queue.default' => 'sync',
    ]);
});

function createSenderProfile(array $ctx, array $overrides = []): OutboundSenderProfile
{
    return app(OutboundSenderProfileService::class)->create($ctx['user'], array_merge([
        'inbox_id' => $ctx['inbox']->id,
        'name' => 'Work',
        'display_name' => 'Alice Sender',
        'signature_text' => 'Regards, Alice',
        'signature_html' => '<p>Regards, <strong>Alice</strong></p>',
    ], $overrides));
}

it('creates updates and deletes sender profiles with ownership checks', function (): void {
    $ctx = outboundSendContext();
    $created = $this->withToken($ctx['token'])->postJson('/api/v1/outbound-sender-profiles', [
        'inbox_id' => $ctx['inbox']->id,
        'name' => 'Primary',
        'display_name' => 'Alice',
        'reply_to_address' => $ctx['inbox']->full_address,
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Primary')
        ->assertJsonPath('data.is_default', true)
        ->assertJsonPath('data.version', 1);

    $id = $created->json('data.id');

    $this->withToken($ctx['token'])->patchJson('/api/v1/outbound-sender-profiles/'.$id, [
        'version' => 1,
        'display_name' => 'Alice Updated',
    ])->assertOk()->assertJsonPath('data.version', 2);

    $this->withToken($ctx['token'])->deleteJson('/api/v1/outbound-sender-profiles/'.$id, ['version' => 2])
        ->assertOk();

    expect(OutboundSenderProfile::withTrashed()->find($id)?->trashed())->toBeTrue();
    expect(AuditLog::query()->where('action', 'outbound.sender_profile_created')->exists())->toBeTrue();
});

it('rejects reply-to addresses outside owned active inboxes', function (): void {
    $ctx = outboundSendContext();

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-sender-profiles', [
        'inbox_id' => $ctx['inbox']->id,
        'name' => 'Bad Reply',
        'reply_to_address' => 'stranger@example.test',
    ])->assertStatus(422)->assertJsonPath('error.code', 'reply_to_forbidden');
});

it('rejects header injection in display names', function (): void {
    $ctx = outboundSendContext();

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-sender-profiles', [
        'inbox_id' => $ctx['inbox']->id,
        'name' => 'Inject',
        'display_name' => "Evil\r\nBcc: hidden@example.test",
    ])->assertStatus(422)->assertJsonPath('error.code', 'display_name_invalid');
});

it('sanitizes html signatures on write', function (): void {
    $ctx = outboundSendContext();
    $profile = createSenderProfile($ctx, [
        'signature_html' => '<p>Safe<script>alert(1)</script></p>',
    ]);

    expect($profile->signature_html)->not->toContain('<script>');
});

it('enforces one default profile per inbox', function (): void {
    $ctx = outboundSendContext();
    $first = createSenderProfile($ctx, ['name' => 'First', 'is_default' => true]);
    $second = createSenderProfile($ctx, ['name' => 'Second']);

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-sender-profiles/'.$second->id.'/default', [
        'version' => $second->version,
    ])->assertOk();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue();
});

it('applies profile identity and signature to drafts once', function (): void {
    $ctx = outboundSendContext();
    $profile = createSenderProfile($ctx);

    $created = $this->withToken($ctx['token'])->postJson('/api/v1/outbound-drafts', [
        'inbox_id' => $ctx['inbox']->id,
        'operation' => 'send',
        'sender_profile_id' => $profile->id,
        'text_body' => 'Hello',
    ])->assertCreated();

    $draft = OutboundMessage::query()->findOrFail($created->json('data.id'));
    expect($draft->sender_profile_id)->toBe($profile->id)
        ->and($draft->from_display_name)->toBe('Alice Sender')
        ->and($draft->text_body)->toContain('[[outbound-sig-start]]')
        ->and($draft->text_body)->toContain('Regards, Alice');

    $this->withToken($ctx['token'])->patchJson('/api/v1/outbound-drafts/'.$draft->id, [
        'version' => $draft->draft_version,
        'sender_profile_id' => $profile->id,
        'text_body' => 'Hello again',
    ])->assertOk();

    $fresh = $draft->fresh();
    expect(substr_count($fresh->text_body ?? '', '[[outbound-sig-start]]'))->toBe(1);
});

it('clears incompatible profile when inbox changes on draft update', function (): void {
    $ctx = outboundSendContext();
    $otherInbox = Inbox::query()->create([
        'domain_id' => $ctx['domain']->id,
        'user_id' => $ctx['user']->id,
        'local_part' => 'other',
        'full_address' => 'other@'.$ctx['domain']->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
    ]);
    $profile = createSenderProfile($ctx);
    $draft = app(OutboundDraftService::class)->create($ctx['user'], [
        'inbox_id' => $ctx['inbox']->id,
        'operation' => 'send',
        'sender_profile_id' => $profile->id,
        'text_body' => 'Body',
    ]);

    app(OutboundDraftService::class)->update($ctx['user'], $draft->id, [
        'version' => $draft->draft_version,
        'inbox_id' => $otherInbox->id,
    ]);

    expect($draft->fresh()->sender_profile_id)->toBeNull();
});

it('snapshots identity on schedule and ignores later profile edits', function (): void {
    Queue::fake();
    config(['outbound.schedule.enabled' => true]);
    $ctx = outboundSendContext();
    $profile = createSenderProfile($ctx, ['display_name' => 'Scheduled Name']);

    $draft = app(OutboundDraftService::class)->create($ctx['user'], [
        'inbox_id' => $ctx['inbox']->id,
        'operation' => 'send',
        'sender_profile_id' => $profile->id,
        'to' => ['recipient@example.test'],
        'subject' => 'Schedule me',
        'text_body' => 'Body',
    ]);

    $utc = now('UTC')->addHours(2);
    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-drafts/'.$draft->id.'/schedule', [
        'version' => $draft->fresh()->draft_version,
        'local_date' => $utc->format('Y-m-d'),
        'local_time' => $utc->format('H:i'),
        'timezone' => 'UTC',
    ])->assertOk()->assertJsonPath('data.state', 'scheduled');

    app(OutboundSenderProfileService::class)->update($ctx['user'], $profile->id, [
        'display_name' => 'Changed After Schedule',
    ], $profile->version);

    $scheduled = $draft->fresh();
    expect($scheduled->from_display_name)->toBe('Scheduled Name');
});

it('strips signature markers before transport and passes reply-to', function (): void {
    $ctx = outboundSendContext();
    $replyInbox = Inbox::query()->create([
        'domain_id' => $ctx['domain']->id,
        'user_id' => $ctx['user']->id,
        'local_part' => 'replies',
        'full_address' => 'replies@'.$ctx['domain']->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
    ]);
    $profile = createSenderProfile($ctx, [
        'reply_to_address' => $replyInbox->full_address,
        'reply_to_name' => 'Reply Desk',
    ]);

    $draft = app(OutboundDraftService::class)->create($ctx['user'], [
        'inbox_id' => $ctx['inbox']->id,
        'operation' => 'send',
        'sender_profile_id' => $profile->id,
        'to' => ['recipient@example.test'],
        'subject' => 'With profile',
        'text_body' => 'Hello',
    ]);

    app(OutboundDraftService::class)->submit($ctx['user'], $draft->id, $draft->fresh()->draft_version);

    /** @var FakeOutboundTransport $transport */
    $transport = app(OutboundTransportInterface::class);
    expect($transport->sent)->not->toBeEmpty();
    $payload = $transport->sent[0];
    expect($payload->textBody)->not->toContain('[[outbound-sig-start]]')
        ->and($payload->textBody)->toContain('Regards, Alice')
        ->and($payload->replyToAddress)->toBe('replies@'.$ctx['domain']->domain)
        ->and($payload->replyToName)->toBe('Reply Desk')
        ->and($payload->fromDisplayName)->toBe('Alice Sender');
});

it('clears sender_profile_id on draft when profile deleted', function (): void {
    $ctx = outboundSendContext();
    $profile = createSenderProfile($ctx);
    $draft = app(OutboundDraftService::class)->create($ctx['user'], [
        'inbox_id' => $ctx['inbox']->id,
        'operation' => 'send',
        'sender_profile_id' => $profile->id,
        'text_body' => 'Body',
    ]);

    app(OutboundSenderProfileService::class)->delete($ctx['user'], $profile->id, $profile->version);

    expect($draft->fresh()->sender_profile_id)->toBeNull();
});

it('does not expose signature content in audit metadata', function (): void {
    $ctx = outboundSendContext();
    createSenderProfile($ctx, ['signature_text' => 'SECRET SIG', 'display_name' => 'SECRET NAME']);

    $log = AuditLog::query()->where('action', 'outbound.sender_profile_created')->latest()->first();
    expect($log)->not->toBeNull();
    $encoded = json_encode($log->metadata ?? []);
    expect($encoded)->not->toContain('SECRET SIG')
        ->and($encoded)->not->toContain('SECRET NAME');
});

it('renders sender profile management page for owner only', function (): void {
    $ctx = outboundSendContext();
    createSenderProfile($ctx);

    $this->actingAs($ctx['user'])->get(route('outbound-sender-profiles.index'))
        ->assertOk()
        ->assertSee('Work');

    $other = User::factory()->create();
    $this->actingAs($other)->get(route('outbound-sender-profiles.index'))
        ->assertOk()
        ->assertDontSee('Work');
});

it('detects profile version conflicts', function (): void {
    $ctx = outboundSendContext();
    $profile = createSenderProfile($ctx);

    $this->withToken($ctx['token'])->patchJson('/api/v1/outbound-sender-profiles/'.$profile->id, [
        'version' => 99,
        'name' => 'Stale',
    ])->assertStatus(409)->assertJsonPath('error.code', 'profile_conflict');
});

it('revalidates reply-to at submit time when inbox expires', function (): void {
    $ctx = outboundSendContext();
    $replyInbox = Inbox::query()->create([
        'domain_id' => $ctx['domain']->id,
        'user_id' => $ctx['user']->id,
        'local_part' => 'expiring',
        'full_address' => 'expiring@'.$ctx['domain']->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
        'expires_at' => now()->addHour(),
    ]);
    $profile = createSenderProfile($ctx, ['reply_to_address' => $replyInbox->full_address]);
    $draft = app(OutboundDraftService::class)->create($ctx['user'], [
        'inbox_id' => $ctx['inbox']->id,
        'operation' => 'send',
        'sender_profile_id' => $profile->id,
        'to' => ['recipient@example.test'],
        'subject' => 'Test',
        'text_body' => 'Body',
    ]);

    $replyInbox->forceFill(['expires_at' => now()->subMinute()])->save();

    expect(fn () => app(OutboundDraftService::class)->submit($ctx['user'], $draft->id, $draft->fresh()->draft_version))
        ->toThrow(OutboundSendException::class, 'Reply-To must be an address of one of your active inboxes.');
});

it('supports direct send with optional sender_profile_id', function (): void {
    Queue::fake();
    $ctx = outboundSendContext();
    $profile = createSenderProfile($ctx);

    $this->withToken($ctx['token'])->postJson('/api/v1/outbound-messages', outboundPayload($ctx, [
        'sender_profile_id' => $profile->id,
    ]))->assertCreated()
        ->assertJsonPath('data.from.name', 'Alice Sender')
        ->assertJsonPath('data.sender_profile_id', $profile->id);
});
