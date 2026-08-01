<?php

declare(strict_types=1);

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Contracts\AttachmentScannerInterface;
use App\DTOs\Attachment\AttachmentScanRequest;
use App\DTOs\Attachment\AttachmentScanResultData;
use App\Enums\AttachmentScanResult;
use App\Enums\AttachmentScanStatus;
use App\Enums\PlatformRole;
use App\Jobs\ScanInboundAttachmentJob;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Email;
use App\Models\EmailProcessingLog;
use App\Models\Inbox;
use App\Models\User;
use App\Policies\AttachmentVisibilityPolicy;
use App\Services\Inbound\AttachmentScanService;
use Database\Seeders\CommercialPlanFeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/** @param array<string, mixed> $overrides */
function ingestionScanAttachment(array $overrides = []): Attachment
{
    $domain = Domain::query()->create([
        'domain' => 'ingest-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Ingest',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create([
        'domain_id' => $domain->id,
        'local_part' => 'ingest',
        'full_address' => 'ingest@'.$domain->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
    ]);
    $email = Email::query()->create([
        'inbox_id' => $inbox->id,
        'message_id' => 'ingest-'.bin2hex(random_bytes(4)),
        'sender_email' => 'a@example.test',
        'recipient_email' => $inbox->full_address,
        'received_at' => now(),
        'size_bytes' => 3,
        'processing_status' => 'received',
    ]);

    return Attachment::query()->create(array_merge([
        'email_id' => $email->id,
        'original_filename' => 'file.txt',
        'stored_filename' => 'opaque',
        'mime_type' => 'text/plain',
        'size_bytes' => 3,
        'checksum_sha256' => hash('sha256', 'abc'),
        'storage_disk' => 'attachments',
        'storage_path' => 'quarantine/'.$email->id.'/opaque',
        'scan_status' => AttachmentScanStatus::Pending,
        'is_safe' => null,
        'metadata' => ['inline' => false, 'content_id' => null],
    ], $overrides));
}

function bindScannerResult(AttachmentScanResult $result, ?string $signature = null, ?string $version = 'test'): void
{
    app()->instance(AttachmentScannerInterface::class, new class($result, $signature, $version) implements AttachmentScannerInterface
    {
        public function __construct(
            private AttachmentScanResult $result,
            private ?string $signature,
            private ?string $version,
        ) {}

        public function scan(AttachmentScanRequest $request): AttachmentScanResultData
        {
            return new AttachmentScanResultData($this->result, $this->signature, $this->version);
        }
    });
}

it('begins new attachments as pending before scanning', function (): void {
    $attachment = ingestionScanAttachment();

    expect($attachment->scan_status)->toBe(AttachmentScanStatus::Pending)
        ->and($attachment->is_safe)->toBeNull()
        ->and($attachment->scanned_at)->toBeNull();
});

it('marks a successful clean scan and records the audit event', function (): void {
    config(['attachments.scanner_backend' => 'clamav']);
    Storage::fake('attachments');
    $attachment = ingestionScanAttachment();
    Storage::disk('attachments')->put($attachment->storage_path, 'abc');
    bindScannerResult(AttachmentScanResult::Clean);

    $result = app(AttachmentScanService::class)->scan($attachment);

    expect($result->scan_status)->toBe(AttachmentScanStatus::Clean)
        ->and($result->is_safe)->toBeTrue()
        ->and($result->scanned_at)->not->toBeNull()
        ->and($result->metadata['inline'] ?? null)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'attachment.scan_started')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'attachment.scan_clean')->count())->toBe(1);
});

it('marks an infected scan result and sanitizes threat metadata', function (): void {
    config(['attachments.scanner_backend' => 'clamav']);
    Storage::fake('attachments');
    $attachment = ingestionScanAttachment();
    Storage::disk('attachments')->put($attachment->storage_path, 'abc');
    bindScannerResult(AttachmentScanResult::Infected, "Eicar-Test-Signature!\n/secret/path");

    $result = app(AttachmentScanService::class)->scan($attachment);

    expect($result->scan_status)->toBe(AttachmentScanStatus::Infected)
        ->and($result->is_safe)->toBeFalse()
        ->and($result->metadata['malware_signature'])->toBe('Eicar-Test-Signaturesecretpath')
        ->and(json_encode($result->metadata))->not->toContain('/secret/path')
        ->and(AuditLog::query()->where('action', 'attachment.scan_infected')->exists())->toBeTrue();
});

it('marks failed and unavailable scanner outcomes as failed', function (): void {
    config(['attachments.scanner_backend' => 'clamav']);
    Storage::fake('attachments');

    $failed = ingestionScanAttachment();
    Storage::disk('attachments')->put($failed->storage_path, 'abc');
    bindScannerResult(AttachmentScanResult::Failed, null, 'clamav:malformed');
    $failedResult = app(AttachmentScanService::class)->scan($failed);
    expect($failedResult->scan_status)->toBe(AttachmentScanStatus::Failed)
        ->and($failedResult->is_safe)->toBeNull()
        ->and(AuditLog::query()->where('action', 'attachment.scan_failed')->exists())->toBeTrue();

    $missing = ingestionScanAttachment(['storage_path' => 'quarantine/missing/object']);
    $missingResult = app(AttachmentScanService::class)->scan($missing);
    expect($missingResult->scan_status)->toBe(AttachmentScanStatus::Failed)
        ->and($missingResult->metadata['scan_error'] ?? null)->toBe('quarantine_missing');
});

it('protects against duplicate concurrent scan claims', function (): void {
    config(['attachments.scanner_backend' => 'clamav']);
    Storage::fake('attachments');
    $attachment = ingestionScanAttachment();
    Storage::disk('attachments')->put($attachment->storage_path, 'abc');

    $counter = (object) ['calls' => 0];
    app()->instance(AttachmentScannerInterface::class, new class($counter) implements AttachmentScannerInterface
    {
        public function __construct(private object $counter) {}

        public function scan(AttachmentScanRequest $request): AttachmentScanResultData
        {
            $this->counter->calls++;

            return new AttachmentScanResultData(AttachmentScanResult::Clean, scannerVersion: 'test');
        }
    });

    $first = app(AttachmentScanService::class)->scan($attachment);
    $second = app(AttachmentScanService::class)->scan($attachment->fresh());

    expect($first->scan_status)->toBe(AttachmentScanStatus::Clean)
        ->and($second->scan_status)->toBe(AttachmentScanStatus::Clean)
        ->and($counter->calls)->toBe(1)
        ->and(AuditLog::query()->where('action', 'attachment.scan_clean')->count())->toBe(1);
});

it('rejects invalid transitions from terminal states', function (): void {
    config(['attachments.scanner_backend' => 'clamav']);
    Storage::fake('attachments');
    $scanner = Mockery::mock(AttachmentScannerInterface::class);
    $scanner->shouldNotReceive('scan');
    app()->instance(AttachmentScannerInterface::class, $scanner);

    foreach ([AttachmentScanStatus::Clean, AttachmentScanStatus::Infected, AttachmentScanStatus::Failed, AttachmentScanStatus::Skipped] as $status) {
        $attachment = ingestionScanAttachment([
            'scan_status' => $status,
            'is_safe' => $status === AttachmentScanStatus::Clean,
            'scanned_at' => now(),
        ]);
        $result = app(AttachmentScanService::class)->scan($attachment);
        expect($result->scan_status)->toBe($status);
    }
});

it('records expected events and remains idempotent on repeated job execution', function (): void {
    config(['attachments.scanner_backend' => 'clamav']);
    Storage::fake('attachments');
    $attachment = ingestionScanAttachment();
    Storage::disk('attachments')->put($attachment->storage_path, 'abc');
    bindScannerResult(AttachmentScanResult::Clean);

    $job = new ScanInboundAttachmentJob((string) $attachment->id);
    $job->handle(app(AttachmentScanService::class));
    $job->handle(app(AttachmentScanService::class));

    expect($attachment->fresh()->scan_status)->toBe(AttachmentScanStatus::Clean)
        ->and(AuditLog::query()->where('action', 'attachment.scan_clean')->count())->toBe(1)
        ->and(EmailProcessingLog::query()->where('worker', 'attachment-scanner')->count())->toBe(1);
});

it('allows clean downloads and denies non-clean states without exposing threat internals', function (): void {
    config(['api.key_hash_secret' => 'attachment-ingestion-test-secret']);
    app(CommercialPlanFeatureSeeder::class)->run();
    Storage::fake('attachments');
    $owner = User::factory()->create();
    $domain = Domain::query()->create([
        'domain' => 'dl-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'DL',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create([
        'domain_id' => $domain->id,
        'user_id' => $owner->id,
        'local_part' => 'files',
        'full_address' => 'files@'.$domain->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
    ]);
    $email = Email::query()->create([
        'inbox_id' => $inbox->id,
        'message_id' => 'dl-'.bin2hex(random_bytes(3)),
        'sender_email' => 'sender@example.test',
        'recipient_email' => $inbox->full_address,
        'subject' => 'Attachment',
        'received_at' => now(),
        'size_bytes' => 12,
        'processing_status' => 'stored',
        'has_attachments' => true,
        'attachment_count' => 1,
    ]);
    $path = 'quarantine/'.$email->id.'/report.txt';
    Storage::disk('attachments')->put($path, 'safe-content');
    $attachment = Attachment::query()->create([
        'email_id' => $email->id,
        'original_filename' => 'report.txt',
        'stored_filename' => 'stored.txt',
        'mime_type' => 'text/plain',
        'extension' => 'txt',
        'size_bytes' => 12,
        'checksum_sha256' => hash('sha256', 'safe-content'),
        'storage_disk' => 'attachments',
        'storage_path' => $path,
        'scan_status' => AttachmentScanStatus::Clean,
        'is_safe' => true,
        'metadata' => ['malware_signature' => 'should-not-leak'],
    ]);
    $token = app(CreateApiKeyAction::class)->issue(
        userId: $owner->id,
        name: 'ingestion-download-key',
        permissions: ['inboxes:read'],
        user: $owner,
    )->plainToken;
    $url = '/api/v1/inboxes/'.$inbox->id.'/emails/'.$email->id.'/attachments/'.$attachment->id;

    \Pest\Laravel\get($url, ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertStreamedContent('safe-content');

    foreach ([
        AttachmentScanStatus::Pending,
        AttachmentScanStatus::Infected,
        AttachmentScanStatus::Failed,
        AttachmentScanStatus::Skipped,
    ] as $status) {
        $attachment->update([
            'scan_status' => $status,
            'is_safe' => false,
            'metadata' => ['malware_signature' => 'Eicar-Test', 'scan_error' => 'scanner_failed'],
        ]);
        $response = \Pest\Laravel\get($url, ['Authorization' => 'Bearer '.$token]);
        $response->assertNotFound();
        expect($response->getContent())->not->toContain('Eicar-Test')
            ->and($response->getContent())->not->toContain('scanner_failed')
            ->and($response->getContent())->not->toContain($path);
    }
});

it('exposes sanitized scan status to authorized admins via audit logs', function (): void {
    config(['attachments.scanner_backend' => 'clamav']);
    Storage::fake('attachments');
    $attachment = ingestionScanAttachment();
    Storage::disk('attachments')->put($attachment->storage_path, 'abc');
    bindScannerResult(AttachmentScanResult::Infected, 'Win.Test.Virus');

    app(AttachmentScanService::class)->scan($attachment);

    $admin = User::factory()->create(['platform_role' => PlatformRole::Admin]);
    $log = AuditLog::query()->where('action', 'attachment.scan_infected')->firstOrFail();

    expect($admin->isPlatformAdmin())->toBeTrue()
        ->and($log->metadata['result_status'] ?? null)->toBe('infected')
        ->and($log->metadata['threat_label'] ?? null)->toBe('Win.Test.Virus')
        ->and($log->metadata)->not->toHaveKeys([
            'storage_path',
            'content',
            'raw_content',
            'attachment_bytes',
        ])
        ->and((new AttachmentVisibilityPolicy)->view($admin, $attachment->fresh()))->toBeFalse();
});
