<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Enums\AttachmentScanStatus;
use App\Enums\PlatformRole;
use App\Models\ApiRequestLog;
use App\Models\Attachment;
use App\Models\Domain;
use App\Models\Email;
use App\Models\EmailBody;
use App\Models\Inbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'email-visibility-test-secret']);
    Storage::fake('attachments');
});

/**
 * @return array{0: User, 1: string, 2: \App\Models\ApiKey}
 */
function issueInboxReadKey(?User $user = null, array $scopes = ['inboxes:read']): array
{
    $user ??= User::factory()->create(['platform_role' => PlatformRole::User]);
    $issued = app(CreateApiKeyAction::class)->issue(
        userId: $user->id,
        name: 'inbox-read-test',
        permissions: $scopes,
        user: $user,
    );

    return [$user, $issued->plainToken, $issued->apiKey];
}

/**
 * @return array{domain: Domain, inbox: Inbox, email: Email, clean: Attachment, pending: Attachment, infected: Attachment, failed: Attachment}
 */
function ownedInboxEmailFixture(User $owner, array $inboxOverrides = [], array $emailOverrides = []): array
{
    $domain = Domain::query()->create([
        'domain' => 'vis-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Visibility',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create(array_merge([
        'domain_id' => $domain->id,
        'user_id' => $owner->id,
        'local_part' => 'owner',
        'full_address' => 'owner@'.$domain->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
    ], $inboxOverrides));
    $email = Email::query()->create(array_merge([
        'inbox_id' => $inbox->id,
        'message_id' => 'vis-'.bin2hex(random_bytes(4)),
        'sender_name' => 'Sender Name',
        'sender_email' => 'sender@example.test',
        'recipient_email' => $inbox->full_address,
        'subject' => 'Hello visibility',
        'received_at' => now()->subMinute(),
        'size_bytes' => 42,
        'processing_status' => 'stored',
        'headers' => [
            'X-Secret' => 'header-secret-value',
            'Authorization' => 'Bearer header-token-secret',
        ],
        'metadata' => [
            'token' => 'email-metadata-token',
            'api_key' => 'email-metadata-api-key',
            'processing_trace' => 'internal-only',
        ],
    ], $emailOverrides));
    EmailBody::query()->create([
        'email_id' => $email->id,
        'html_body' => '<p>Safe</p><script>alert("xss")</script>',
        'text_body' => 'Plain text body',
        'body_hash' => hash('sha256', 'body'),
        'metadata' => ['secret' => 'body-metadata-secret'],
    ]);

    $cleanPath = 'quarantine/'.$email->id.'/clean.bin';
    Storage::disk('attachments')->put($cleanPath, 'clean-bytes');
    $clean = Attachment::query()->create([
        'email_id' => $email->id,
        'original_filename' => 'clean.txt',
        'stored_filename' => 'clean-stored',
        'mime_type' => 'text/plain',
        'extension' => 'txt',
        'size_bytes' => 10,
        'checksum_sha256' => hash('sha256', 'clean-bytes'),
        'storage_disk' => 'attachments',
        'storage_path' => $cleanPath,
        'scan_status' => AttachmentScanStatus::Clean,
        'is_safe' => true,
        'metadata' => ['scanner_command' => 'clamscan --secret'],
    ]);
    $pending = Attachment::query()->create([
        'email_id' => $email->id,
        'original_filename' => 'pending.bin',
        'stored_filename' => 'pending-stored',
        'mime_type' => 'application/octet-stream',
        'size_bytes' => 2,
        'checksum_sha256' => hash('sha256', 'pending'),
        'storage_disk' => 'attachments',
        'storage_path' => 'quarantine/'.$email->id.'/pending.bin',
        'scan_status' => AttachmentScanStatus::Pending,
        'is_safe' => null,
    ]);
    $infected = Attachment::query()->create([
        'email_id' => $email->id,
        'original_filename' => 'infected.bin',
        'stored_filename' => 'infected-stored',
        'mime_type' => 'application/octet-stream',
        'size_bytes' => 2,
        'checksum_sha256' => hash('sha256', 'infected'),
        'storage_disk' => 'attachments',
        'storage_path' => 'quarantine/'.$email->id.'/infected.bin',
        'scan_status' => AttachmentScanStatus::Infected,
        'is_safe' => false,
        'metadata' => ['signature' => 'Eicar-Test-Signature'],
    ]);
    $failed = Attachment::query()->create([
        'email_id' => $email->id,
        'original_filename' => 'failed.bin',
        'stored_filename' => 'failed-stored',
        'mime_type' => 'application/octet-stream',
        'size_bytes' => 2,
        'checksum_sha256' => hash('sha256', 'failed'),
        'storage_disk' => 'attachments',
        'storage_path' => 'quarantine/'.$email->id.'/failed.bin',
        'scan_status' => AttachmentScanStatus::Failed,
        'is_safe' => null,
    ]);

    return compact('domain', 'inbox', 'email', 'clean', 'pending', 'infected', 'failed');
}

it('allows an inbox owner to list and show emails with the standard pagination envelope', function (): void {
    [$owner, $token] = issueInboxReadKey();
    $fixture = ownedInboxEmailFixture($owner);
    Email::query()->create([
        'inbox_id' => $fixture['inbox']->id,
        'message_id' => 'vis-second-'.bin2hex(random_bytes(3)),
        'sender_email' => 'other@example.test',
        'recipient_email' => $fixture['inbox']->full_address,
        'subject' => 'Second',
        'received_at' => now(),
        'size_bytes' => 1,
        'processing_status' => 'stored',
    ]);

    $list = $this->withToken($token)->getJson('/api/v1/inboxes/'.$fixture['inbox']->id.'/emails?per_page=1');
    $list->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ])
        ->assertJsonMissingPath('links');
    expect($list->json('meta.per_page'))->toBe(1)
        ->and($list->json('meta.total'))->toBe(2)
        ->and($list->json('meta.current_page'))->toBe(1)
        ->and($list->json('meta.last_page'))->toBe(2)
        ->and($list->json('data.0'))->toHaveKeys(['id', 'inbox_id', 'message_id', 'sender', 'recipients', 'subject', 'received_at', 'text_body', 'html_body', 'attachments'])
        ->and($list->json('data.0'))->not->toHaveKeys(['headers', 'metadata', 'processing_status', 'spam_score']);

    $show = $this->withToken($token)->getJson('/api/v1/inboxes/'.$fixture['inbox']->id.'/emails/'.$fixture['email']->id);
    $show->assertOk()
        ->assertJsonPath('data.id', $fixture['email']->id)
        ->assertJsonPath('data.inbox_id', $fixture['inbox']->id)
        ->assertJsonPath('data.message_id', $fixture['email']->message_id)
        ->assertJsonPath('data.subject', 'Hello visibility')
        ->assertJsonPath('data.sender.email', 'sender@example.test')
        ->assertJsonPath('data.recipients.0', $fixture['inbox']->full_address)
        ->assertJsonPath('data.text_body', 'Plain text body');
});

it('returns 404 for other users and does not let operators bypass ownership', function (): void {
    [$owner] = issueInboxReadKey();
    $fixture = ownedInboxEmailFixture($owner);

    [$other, $otherToken] = issueInboxReadKey();
    $this->withToken($otherToken)
        ->getJson('/api/v1/inboxes/'.$fixture['inbox']->id.'/emails')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
    $this->withToken($otherToken)
        ->getJson('/api/v1/inboxes/'.$fixture['inbox']->id.'/emails/'.$fixture['email']->id)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $admin = User::factory()->platformAdmin()->create();
    [, $adminToken] = issueInboxReadKey($admin, ['inboxes:read', 'mail_servers:admin']);
    $this->withToken($adminToken)
        ->getJson('/api/v1/inboxes/'.$fixture['inbox']->id.'/emails')
        ->assertNotFound();
});

it('returns 403 when the API key is missing inboxes:read', function (): void {
    $operator = User::factory()->platformOperator()->create();
    [, $token] = issueInboxReadKey($operator, ['mail_servers:read']);
    [$owner] = issueInboxReadKey();
    $fixture = ownedInboxEmailFixture($owner);

    $this->withToken($token)
        ->getJson('/api/v1/inboxes/'.$fixture['inbox']->id.'/emails')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

it('hides expired inactive and soft-deleted inboxes and soft-deleted emails', function (): void {
    [$owner, $token] = issueInboxReadKey();

    $expired = ownedInboxEmailFixture($owner, ['expires_at' => now()->subMinute()]);
    $this->withToken($token)->getJson('/api/v1/inboxes/'.$expired['inbox']->id.'/emails')->assertNotFound();

    $inactive = ownedInboxEmailFixture($owner, ['is_active' => false]);
    $this->withToken($token)->getJson('/api/v1/inboxes/'.$inactive['inbox']->id.'/emails')->assertNotFound();

    $deletedInbox = ownedInboxEmailFixture($owner);
    $deletedInbox['inbox']->delete();
    $this->withToken($token)->getJson('/api/v1/inboxes/'.$deletedInbox['inbox']->id.'/emails')->assertNotFound();

    $visible = ownedInboxEmailFixture($owner);
    $visible['email']->delete();
    $this->withToken($token)
        ->getJson('/api/v1/inboxes/'.$visible['inbox']->id.'/emails/'.$visible['email']->id)
        ->assertNotFound();
    $list = $this->withToken($token)->getJson('/api/v1/inboxes/'.$visible['inbox']->id.'/emails');
    $list->assertOk();
    expect($list->json('meta.total'))->toBe(0);
});

it('does not treat anonymous inboxes as owned by the API actor', function (): void {
    [$owner, $token] = issueInboxReadKey();
    $anonymous = ownedInboxEmailFixture($owner, ['user_id' => null]);

    $this->withToken($token)
        ->getJson('/api/v1/inboxes/'.$anonymous['inbox']->id.'/emails')
        ->assertNotFound();
});

it('hides pending infected and failed attachments and redacts sensitive fields', function (): void {
    [$owner, $token] = issueInboxReadKey();
    $fixture = ownedInboxEmailFixture($owner);

    $show = $this->withToken($token)->getJson('/api/v1/inboxes/'.$fixture['inbox']->id.'/emails/'.$fixture['email']->id);
    $show->assertOk();

    $payload = json_encode($show->json());
    expect($show->json('data.attachments'))->toHaveCount(1)
        ->and($show->json('data.attachments.0.id'))->toBe($fixture['clean']->id)
        ->and($show->json('data.attachments.0.original_filename'))->toBe('clean.txt')
        ->and($show->json('data.attachments.0'))->not->toHaveKeys(['storage_path', 'storage_disk', 'checksum_sha256', 'metadata', 'stored_filename'])
        ->and($show->json('data.html_body'))->toContain('Safe')
        ->and($show->json('data.html_body'))->not->toContain('<script>')
        ->and($show->json('data.html_body'))->not->toContain('alert("xss")')
        ->and($show->json('data'))->not->toHaveKeys(['headers', 'metadata', 'spam_score', 'processing_status', 'size_bytes'])
        ->and($payload)->not->toContain('header-secret-value')
        ->and($payload)->not->toContain('email-metadata-token')
        ->and($payload)->not->toContain('body-metadata-secret')
        ->and($payload)->not->toContain('clamscan --secret')
        ->and($payload)->not->toContain($fixture['clean']->storage_path)
        ->and($payload)->not->toContain($fixture['clean']->checksum_sha256)
        ->and($payload)->not->toContain('pending.bin')
        ->and($payload)->not->toContain('infected.bin')
        ->and($payload)->not->toContain('failed.bin')
        ->and($payload)->not->toContain('Eicar-Test-Signature');
});

it('keeps request logging and rate limiting intact for inbox email reads', function (): void {
    [$owner, $token, $key] = issueInboxReadKey();
    $key->update(['rate_limit_per_minute' => 1]);
    RateLimiter::clear('api-key:'.$key->id);
    $fixture = ownedInboxEmailFixture($owner);

    $this->withToken($token)->getJson('/api/v1/inboxes/'.$fixture['inbox']->id.'/emails')->assertOk();
    $throttled = $this->withToken($token)->getJson('/api/v1/inboxes/'.$fixture['inbox']->id.'/emails');
    $throttled->assertStatus(429)->assertJsonPath('error.code', 'rate_limit_exceeded');

    $success = ApiRequestLog::query()->where('response_status', 200)->sole();
    expect($success->endpoint)->toBe('api.v1.inboxes.emails.index')
        ->and($success->api_key_id)->toBe((string) $key->id)
        ->and($success->user_id)->toBe((string) $owner->id);

    $json = ApiRequestLog::query()->get()->toJson();
    expect($json)->not->toContain($token)->not->toContain($key->key_hash)->not->toContain('Authorization');
});

it('filters owned email listings by read state, fields, attachments, dates, sort and pagination', function (): void {
    [$owner, $token] = issueInboxReadKey();
    $fixture = ownedInboxEmailFixture($owner);
    $fixture['email']->update(['is_read' => true, 'read_at' => now()->subHour(), 'subject' => 'Invoice alpha', 'sender_email' => 'Sender@Example.test', 'received_at' => now()->subHours(2)]);
    $other = \App\Models\Email::query()->create(['inbox_id' => $fixture['inbox']->id, 'message_id' => 'filter-other-'.bin2hex(random_bytes(3)), 'sender_email' => 'other@example.test', 'recipient_email' => $fixture['inbox']->full_address, 'subject' => 'Report beta', 'received_at' => now()->subHour(), 'size_bytes' => 1, 'processing_status' => 'stored', 'has_attachments' => false, 'is_read' => false]);

    $base = '/api/v1/inboxes/'.$fixture['inbox']->id.'/emails';
    $this->withToken($token)->getJson($base.'?is_read=true&subject=INVOICE&from=sender@example.test&received_before='.urlencode(now()->subHour()->format('Y-m-d H:i:s')).'&sort=received_at&direction=asc&per_page=1')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $fixture['email']->id);
    $this->withToken($token)->getJson($base.'?message_id='.$other->message_id.'&has_attachments=false')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $other->id);
});

it('returns the standard validation envelope for invalid email list filters', function (): void {
    [$owner, $token] = issueInboxReadKey();
    $fixture = ownedInboxEmailFixture($owner);
    $base = '/api/v1/inboxes/'.$fixture['inbox']->id.'/emails';

    $this->withToken($token)->getJson($base.'?is_read=maybe')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->withToken($token)->getJson($base.'?sort=raw_headers')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->withToken($token)->getJson($base.'?received_after=not-a-date')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});
