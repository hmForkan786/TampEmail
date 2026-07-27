<?php

declare(strict_types=1);

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Actions\Attachment\PermanentlyDeleteQuarantinedAttachmentAction;
use App\Actions\Attachment\RescanFailedAttachmentAction;
use App\Enums\AttachmentScanStatus;
use App\Filament\Admin\Resources\QuarantinedAttachments\Pages\ListQuarantinedAttachments;
use App\Filament\Admin\Resources\QuarantinedAttachments\Pages\ViewQuarantinedAttachment;
use App\Filament\Admin\Resources\QuarantinedAttachments\QuarantinedAttachmentResource;
use App\Jobs\ScanInboundAttachmentJob;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Inbox;
use App\Models\User;
use App\Policies\AttachmentVisibilityPolicy;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('attachments');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function quarantineFixture(AttachmentScanStatus $status = AttachmentScanStatus::Infected, ?User $owner = null): array
{
    $owner ??= User::factory()->create();
    $domain = Domain::query()->create([
        'domain' => 'q-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Quarantine',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create([
        'domain_id' => $domain->id,
        'user_id' => $owner->id,
        'local_part' => 'box',
        'full_address' => 'box@'.$domain->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
    ]);
    $email = Email::query()->create([
        'inbox_id' => $inbox->id,
        'message_id' => 'q-'.bin2hex(random_bytes(3)),
        'sender_email' => 'sender@test',
        'recipient_email' => $inbox->full_address,
        'received_at' => now(),
        'size_bytes' => 12,
        'processing_status' => 'stored',
        'has_attachments' => true,
        'attachment_count' => 1,
    ]);
    $path = 'quarantine/'.$email->id.'/'.bin2hex(random_bytes(8));
    Storage::disk('attachments')->put($path, 'quarantine-bytes');
    $attachment = Attachment::query()->create([
        'email_id' => $email->id,
        'original_filename' => 'payload.bin',
        'stored_filename' => basename($path),
        'mime_type' => 'application/octet-stream',
        'extension' => 'bin',
        'size_bytes' => 16,
        'checksum_sha256' => hash('sha256', 'quarantine-bytes'),
        'storage_disk' => 'attachments',
        'storage_path' => $path,
        'scan_status' => $status,
        'is_safe' => $status === AttachmentScanStatus::Infected ? false : null,
        'scanned_at' => now(),
        'metadata' => [
            'malware_signature' => $status === AttachmentScanStatus::Infected ? 'Eicar-Test-Signature' : null,
            'scan_error' => $status === AttachmentScanStatus::Failed ? 'scanner_failed' : null,
        ],
    ]);

    return compact('owner', 'inbox', 'email', 'attachment', 'path');
}

it('treats infected and failed attachments as quarantined', function (): void {
    $infected = quarantineFixture(AttachmentScanStatus::Infected)['attachment'];
    $failed = quarantineFixture(AttachmentScanStatus::Failed)['attachment'];
    $policy = app(AttachmentVisibilityPolicy::class);

    expect($infected->isQuarantined())->toBeTrue()
        ->and($failed->isQuarantined())->toBeTrue()
        ->and($policy->isQuarantined($infected))->toBeTrue()
        ->and($policy->mayIncludeInOutgoing($infected))->toBeFalse()
        ->and($policy->mayIncludeInOutgoing($failed))->toBeFalse();
});

it('blocks owner and api downloads for quarantined attachments', function (): void {
    config(['api.key_hash_secret' => 'quarantine-test-secret']);
    $fixture = quarantineFixture(AttachmentScanStatus::Infected);
    ensureFreeCommercialUser($fixture['owner']);
    ensureCommercialApiAccess($fixture['owner'], ['inboxes:read']);
    $token = app(CreateApiKeyAction::class)->issue(
        userId: $fixture['owner']->id,
        name: 'q-key',
        permissions: ['inboxes:read'],
        user: $fixture['owner'],
    )->plainToken;
    $url = '/api/v1/inboxes/'.$fixture['inbox']->id.'/emails/'.$fixture['email']->id.'/attachments/'.$fixture['attachment']->id;

    $response = $this->withToken($token)->get($url);
    $response->assertNotFound();
    expect($response->getContent())->not->toContain('Eicar-Test-Signature')
        ->and($response->getContent())->not->toContain($fixture['path'])
        ->and($response->getContent())->not->toContain('quarantine-bytes');
});

it('allows admins to view sanitized quarantine metadata without download actions', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $fixture = quarantineFixture(AttachmentScanStatus::Infected);

    $this->actingAs($admin)
        ->get(QuarantinedAttachmentResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Eicar-Test-Signature')
        ->assertDontSee($fixture['path'])
        ->assertDontSee('quarantine-bytes');

    Livewire::actingAs($admin)
        ->test(ViewQuarantinedAttachment::class, ['record' => $fixture['attachment']->id])
        ->assertSuccessful()
        ->assertSee('Eicar-Test-Signature')
        ->assertActionDoesNotExist('download')
        ->assertActionHidden('rescan')
        ->assertActionVisible('permanentlyDelete');
});

it('denies unauthorized users from quarantine administration and rescan', function (): void {
    $fixture = quarantineFixture(AttachmentScanStatus::Failed);
    $operator = User::factory()->platformOperator()->create();
    $owner = $fixture['owner'];

    foreach ([$operator, $owner, User::factory()->create()] as $actor) {
        expect(fn () => app(RescanFailedAttachmentAction::class)->execute($actor, $fixture['attachment']))
            ->toThrow(AuthorizationException::class);
        $this->actingAs($actor);
        expect(QuarantinedAttachmentResource::canViewAny())->toBeFalse();
        $this->get(QuarantinedAttachmentResource::getUrl('index'))->assertForbidden();
    }
});

it('rescans failed attachments and rejects casual infected rescans', function (): void {
    Queue::fake();
    $admin = User::factory()->platformAdmin()->create();
    $failed = quarantineFixture(AttachmentScanStatus::Failed)['attachment'];
    $infected = quarantineFixture(AttachmentScanStatus::Infected)['attachment'];

    $updated = app(RescanFailedAttachmentAction::class)->execute($admin, $failed);
    expect($updated->scan_status)->toBe(AttachmentScanStatus::Pending)
        ->and($updated->metadata['manual_rescan_count'] ?? null)->toBe(1)
        ->and($updated->metadata['last_rescan_by'] ?? null)->toBe((string) $admin->id)
        ->and(AuditLog::query()->where('action', 'attachment.rescan_requested')->count())->toBe(1);
    Queue::assertPushed(ScanInboundAttachmentJob::class, fn ($job): bool => $job->attachmentId === (string) $failed->id);

    expect(fn () => app(RescanFailedAttachmentAction::class)->execute($admin, $infected))
        ->toThrow(AuthorizationException::class);

    // Duplicate rescan while pending is denied by policy (failed-only).
    expect(fn () => app(RescanFailedAttachmentAction::class)->execute($admin, $updated->fresh()))
        ->toThrow(AuthorizationException::class);
});

it('permanently deletes quarantined bytes while preserving audit evidence and parent email', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $fixture = quarantineFixture(AttachmentScanStatus::Infected);
    $attachment = $fixture['attachment'];
    $path = $fixture['path'];
    $emailId = $fixture['email']->id;

    expect(Storage::disk('attachments')->exists($path))->toBeTrue();

    $deleted = app(PermanentlyDeleteQuarantinedAttachmentAction::class)->execute($admin, $attachment);

    expect($deleted->trashed())->toBeTrue()
        ->and(Storage::disk('attachments')->exists($path))->toBeFalse()
        ->and(Email::query()->whereKey($emailId)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'attachment.quarantine_purged')->exists())->toBeTrue();

    $purgeMeta = json_encode(AuditLog::query()->where('action', 'attachment.quarantine_purged')->first()->metadata);
    expect($purgeMeta)->not->toContain('quarantine-bytes')->and($purgeMeta)->not->toContain($path);

    // Idempotent second delete.
    $again = app(PermanentlyDeleteQuarantinedAttachmentAction::class)->execute($admin, $deleted);
    expect($again->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'attachment.quarantine_purged')->count())->toBe(1);
});

it('excludes quarantined attachments from outgoing eligibility', function (): void {
    $clean = quarantineFixture(AttachmentScanStatus::Clean);
    $clean['attachment']->update(['scan_status' => AttachmentScanStatus::Clean, 'is_safe' => true]);
    Storage::disk('attachments')->put($clean['path'], 'quarantine-bytes');

    $policy = app(AttachmentVisibilityPolicy::class);
    expect($policy->mayIncludeInOutgoing($clean['attachment']->fresh()))->toBeTrue()
        ->and($policy->mayIncludeInOutgoing(quarantineFixture(AttachmentScanStatus::Infected)['attachment']))->toBeFalse()
        ->and($policy->mayIncludeInOutgoing(quarantineFixture(AttachmentScanStatus::Failed)['attachment']))->toBeFalse()
        ->and($policy->mayIncludeInOutgoing(quarantineFixture(AttachmentScanStatus::Pending)['attachment']))->toBeFalse();
});

it('lists quarantine records for admins and hides storage paths', function (): void {
    $admin = User::factory()->platformAdmin()->create();
    $infected = quarantineFixture(AttachmentScanStatus::Infected);
    $failed = quarantineFixture(AttachmentScanStatus::Failed);

    Livewire::actingAs($admin)
        ->test(ListQuarantinedAttachments::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$infected['attachment'], $failed['attachment']])
        ->assertDontSee($infected['path']);
});
