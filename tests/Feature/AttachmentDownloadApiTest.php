<?php

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Inbox;
use App\Models\ApiRequestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['api.key_hash_secret' => 'attachment-download-test-secret']);
    Storage::fake('attachments');
});

function attachmentDownloadKey(User $owner, array $scopes = ['inboxes:read']): string
{
    return app(CreateApiKeyAction::class)->issue(
        userId: $owner->id,
        name: 'attachment-download-key',
        permissions: $scopes,
        user: $owner,
    )->plainToken;
}

function attachmentDownloadFixture(User $owner, array $inboxOverrides = []): array
{
    $domain = Domain::query()->create([
        'domain' => 'download-'.bin2hex(random_bytes(3)).'.test', 'display_name' => 'Download',
        'is_active' => true, 'is_public' => true, 'allow_registration' => true, 'is_healthy' => true,
        'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create(array_merge([
        'domain_id' => $domain->id, 'user_id' => $owner->id, 'local_part' => 'files',
        'full_address' => 'files@'.$domain->domain, 'inbox_type' => 'temporary', 'is_active' => true,
    ], $inboxOverrides));
    $email = Email::query()->create([
        'inbox_id' => $inbox->id, 'message_id' => 'download-'.bin2hex(random_bytes(3)),
        'sender_email' => 'sender@example.test', 'recipient_email' => $inbox->full_address,
        'subject' => 'Attachment', 'received_at' => now(), 'size_bytes' => 12,
        'processing_status' => 'stored', 'has_attachments' => true, 'attachment_count' => 1,
    ]);
    $path = 'quarantine/'.$email->id.'/report.txt';
    Storage::disk('attachments')->put($path, 'safe-content');
    $attachment = Attachment::query()->create([
        'email_id' => $email->id, 'original_filename' => 'report.txt', 'stored_filename' => 'stored.txt',
        'mime_type' => 'text/plain', 'extension' => 'txt', 'size_bytes' => 12,
        'checksum_sha256' => hash('sha256', 'safe-content'), 'storage_disk' => 'attachments',
        'storage_path' => $path, 'scan_status' => AttachmentScanStatus::Clean, 'is_safe' => true,
    ]);

    return compact('inbox', 'email', 'attachment');
}

it('downloads a clean owner attachment from the private disk', function (): void {
    $owner = User::factory()->create();
    $fixture = attachmentDownloadFixture($owner);

    $response = $this->withToken(attachmentDownloadKey($owner))->get(
        '/api/v1/inboxes/'.$fixture['inbox']->id.'/emails/'.$fixture['email']->id.'/attachments/'.$fixture['attachment']->id,
    );

    $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'attachment; filename=report.txt')
        ->assertStreamedContent('safe-content');

    $log = ApiRequestLog::query()->latest('created_at')->firstOrFail();
    expect($log->endpoint)->toBe('api.v1.inboxes.emails.attachments.download')
        ->and(json_encode($log->toArray()))->not->toContain('safe-content');
});

it('hides attachments from other owners and keys without the read scope', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $fixture = attachmentDownloadFixture($owner);
    $url = '/api/v1/inboxes/'.$fixture['inbox']->id.'/emails/'.$fixture['email']->id.'/attachments/'.$fixture['attachment']->id;

    $this->withToken(attachmentDownloadKey($other))->get($url)->assertNotFound();
    $this->withToken(attachmentDownloadKey($owner, []))->get($url)->assertForbidden();
});

it('blocks unsafe scan states and missing files', function (): void {
    $owner = User::factory()->create();
    $fixture = attachmentDownloadFixture($owner);
    $url = fn (): string => '/api/v1/inboxes/'.$fixture['inbox']->id.'/emails/'.$fixture['email']->id.'/attachments/'.$fixture['attachment']->id;
    $token = attachmentDownloadKey($owner);

    foreach ([AttachmentScanStatus::Pending, AttachmentScanStatus::Scanning, AttachmentScanStatus::Failed, AttachmentScanStatus::Infected] as $status) {
        $fixture['attachment']->update(['scan_status' => $status, 'is_safe' => false]);
        $this->withToken($token)->get($url())->assertNotFound();
    }

    $fixture['attachment']->update(['scan_status' => AttachmentScanStatus::Clean, 'is_safe' => true]);
    Storage::disk('attachments')->delete($fixture['attachment']->storage_path);
    $this->withToken($token)->get($url())->assertNotFound();
});

it('hides expired or deleted inboxes and rejects unsafe filenames and paths', function (): void {
    $owner = User::factory()->create();
    $fixture = attachmentDownloadFixture($owner, ['expires_at' => now()->subMinute()]);
    $url = '/api/v1/inboxes/'.$fixture['inbox']->id.'/emails/'.$fixture['email']->id.'/attachments/'.$fixture['attachment']->id;
    $this->withToken(attachmentDownloadKey($owner))->get($url)->assertNotFound();

    $fixture = attachmentDownloadFixture($owner);
    $fixture['attachment']->update(['original_filename' => "../evil\r\n.txt", 'storage_path' => '../outside.txt']);
    $this->withToken(attachmentDownloadKey($owner))->get(
        '/api/v1/inboxes/'.$fixture['inbox']->id.'/emails/'.$fixture['email']->id.'/attachments/'.$fixture['attachment']->id,
    )->assertNotFound();
});
