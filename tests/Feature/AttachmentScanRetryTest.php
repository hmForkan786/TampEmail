<?php

declare(strict_types=1);

use App\Contracts\AttachmentScannerInterface;
use App\DTOs\Attachment\AttachmentScanRequest;
use App\DTOs\Attachment\AttachmentScanResultData;
use App\Enums\AttachmentScanResult;
use App\Enums\AttachmentScanStatus;
use App\Jobs\ScanInboundAttachmentJob;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Inbox;
use App\Services\Inbound\AttachmentScanRetry;
use App\Services\Inbound\AttachmentScanRetryableException;
use App\Services\Inbound\AttachmentScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function retryScanAttachment(array $overrides = []): Attachment
{
    $domain = Domain::query()->create([
        'domain' => 'retry-'.bin2hex(random_bytes(3)).'.test',
        'display_name' => 'Retry',
        'is_active' => true,
        'is_public' => true,
        'allow_registration' => true,
        'is_healthy' => true,
        'retention_hours' => 24,
    ]);
    $inbox = Inbox::query()->create([
        'domain_id' => $domain->id,
        'local_part' => 'retry',
        'full_address' => 'retry@'.$domain->domain,
        'inbox_type' => 'temporary',
        'is_active' => true,
    ]);
    $email = Email::query()->create([
        'inbox_id' => $inbox->id,
        'message_id' => 'retry-'.bin2hex(random_bytes(3)),
        'sender_email' => 'a@test',
        'recipient_email' => $inbox->full_address,
        'received_at' => now(),
        'size_bytes' => 1,
        'processing_status' => 'received',
    ]);

    return Attachment::query()->create(array_merge([
        'email_id' => $email->id,
        'original_filename' => 'x',
        'stored_filename' => 'x',
        'mime_type' => 'text/plain',
        'size_bytes' => 1,
        'checksum_sha256' => hash('sha256', 'x'),
        'storage_disk' => 'attachments',
        'storage_path' => 'quarantine/'.$email->id.'/x',
        'scan_status' => AttachmentScanStatus::Pending,
        'is_safe' => null,
        'metadata' => [],
    ], $overrides));
}

function bindRetryScanner(string $version, AttachmentScanResult $result = AttachmentScanResult::Failed): void
{
    app()->instance(AttachmentScannerInterface::class, new class($result, $version) implements AttachmentScannerInterface
    {
        public function __construct(private AttachmentScanResult $result, private string $version) {}

        public function scan(AttachmentScanRequest $request): AttachmentScanResultData
        {
            return new AttachmentScanResultData($this->result, scannerVersion: $this->version);
        }
    });
}

it('schedules retries for connection and timeout failures with configured backoff', function (): void {
    config([
        'attachments.scanner_backend' => 'clamav',
        'attachments.retry.max_attempts' => 3,
        'attachments.retry.backoff_seconds' => '30,120,600',
    ]);
    Storage::fake('attachments');

    expect(AttachmentScanRetry::backoffSeconds())->toBe([30, 120, 600])
        ->and(AttachmentScanRetry::maxAttempts())->toBe(3);

    foreach (['clamav:unavailable', 'clamav:timeout'] as $code) {
        $attachment = retryScanAttachment();
        Storage::disk('attachments')->put($attachment->storage_path, 'x');
        bindRetryScanner($code);

        expect(fn () => app(AttachmentScanService::class)->scan($attachment))
            ->toThrow(AttachmentScanRetryableException::class);

        $fresh = $attachment->fresh();
        expect($fresh->scan_status)->toBe(AttachmentScanStatus::Pending)
            ->and($fresh->metadata['scan_attempt_count'] ?? null)->toBe(1)
            ->and(AuditLog::query()->where('action', 'attachment.scan_retry_scheduled')->where('auditable_id', $attachment->id)->exists())->toBeTrue();

        $event = AuditLog::query()->where('action', 'attachment.scan_retry_scheduled')->where('auditable_id', $attachment->id)->latest('id')->first();
        expect($event->metadata['next_retry_seconds'] ?? null)->toBe(30)
            ->and($event->metadata['attempt'] ?? null)->toBe(1)
            ->and($event->metadata['max_attempts'] ?? null)->toBe(3);
    }
});

it('increments attempt count exactly once per execution and succeeds after retry', function (): void {
    config(['attachments.scanner_backend' => 'clamav', 'attachments.retry.max_attempts' => 3]);
    Storage::fake('attachments');
    $attachment = retryScanAttachment();
    Storage::disk('attachments')->put($attachment->storage_path, 'x');

    bindRetryScanner('clamav:unavailable');
    expect(fn () => app(AttachmentScanService::class)->scan($attachment))->toThrow(AttachmentScanRetryableException::class);
    expect($attachment->fresh()->metadata['scan_attempt_count'] ?? null)->toBe(1);

    // Duplicate claim while pending after release still increments on next successful claim only once per call.
    bindRetryScanner('clamav:unavailable');
    expect(fn () => app(AttachmentScanService::class)->scan($attachment->fresh()))->toThrow(AttachmentScanRetryableException::class);
    expect($attachment->fresh()->metadata['scan_attempt_count'] ?? null)->toBe(2);

    app()->instance(AttachmentScannerInterface::class, new class implements AttachmentScannerInterface
    {
        public function scan(AttachmentScanRequest $request): AttachmentScanResultData
        {
            return new AttachmentScanResultData(AttachmentScanResult::Clean, scannerVersion: 'test');
        }
    });
    $clean = app(AttachmentScanService::class)->scan($attachment->fresh());
    expect($clean->scan_status)->toBe(AttachmentScanStatus::Clean)
        ->and($clean->metadata['scan_attempt_count'] ?? null)->toBe(3);
});

it('does not retry infected results and terminates non-retryable failures immediately', function (): void {
    config(['attachments.scanner_backend' => 'clamav', 'attachments.retry.max_attempts' => 3]);
    Storage::fake('attachments');

    $infected = retryScanAttachment();
    Storage::disk('attachments')->put($infected->storage_path, 'x');
    bindRetryScanner('test', AttachmentScanResult::Infected);
    $infectedResult = app(AttachmentScanService::class)->scan($infected);
    expect($infectedResult->scan_status)->toBe(AttachmentScanStatus::Infected)
        ->and(AuditLog::query()->where('action', 'attachment.scan_retry_scheduled')->where('auditable_id', $infected->id)->exists())->toBeFalse();

    $malformed = retryScanAttachment();
    Storage::disk('attachments')->put($malformed->storage_path, 'x');
    bindRetryScanner('clamav:malformed');
    $failed = app(AttachmentScanService::class)->scan($malformed);
    expect($failed->scan_status)->toBe(AttachmentScanStatus::Failed)
        ->and(AuditLog::query()->where('action', 'attachment.scan_retry_scheduled')->where('auditable_id', $malformed->id)->exists())->toBeFalse();
});

it('marks failed and records exhaustion when retries are spent', function (): void {
    config(['attachments.scanner_backend' => 'clamav', 'attachments.retry.max_attempts' => 2]);
    Storage::fake('attachments');
    $attachment = retryScanAttachment();
    Storage::disk('attachments')->put($attachment->storage_path, 'x');
    bindRetryScanner('clamav:unavailable');

    expect(fn () => app(AttachmentScanService::class)->scan($attachment))->toThrow(AttachmentScanRetryableException::class);
    $exhausted = app(AttachmentScanService::class)->scan($attachment->fresh());

    expect($exhausted->scan_status)->toBe(AttachmentScanStatus::Failed)
        ->and($exhausted->is_safe)->toBeNull()
        ->and($exhausted->isQuarantined())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'attachment.scan_retry_exhausted')->where('auditable_id', $attachment->id)->exists())->toBeTrue();
});

it('ignores stale jobs against terminal state and deleted attachments', function (): void {
    config(['attachments.scanner_backend' => 'clamav']);
    Storage::fake('attachments');
    $scanner = Mockery::mock(AttachmentScannerInterface::class);
    $scanner->shouldNotReceive('scan');
    app()->instance(AttachmentScannerInterface::class, $scanner);

    $terminal = retryScanAttachment(['scan_status' => AttachmentScanStatus::Clean, 'is_safe' => true]);
    (new ScanInboundAttachmentJob((string) $terminal->id))->handle(app(AttachmentScanService::class));
    expect($terminal->fresh()->scan_status)->toBe(AttachmentScanStatus::Clean);

    $deleted = retryScanAttachment();
    Storage::disk('attachments')->put($deleted->storage_path, 'x');
    $deleted->delete();
    (new ScanInboundAttachmentJob((string) $deleted->id))->handle(app(AttachmentScanService::class));
    expect(Attachment::withTrashed()->find($deleted->id)->trashed())->toBeTrue();
});

it('falls back safely for malformed retry configuration and wires job settings', function (): void {
    config([
        'attachments.retry.max_attempts' => 0,
        'attachments.retry.backoff_seconds' => 'nope,bad',
    ]);
    expect(AttachmentScanRetry::maxAttempts())->toBe(3)
        ->and(AttachmentScanRetry::backoffSeconds())->toBe([60, 300, 900]);

    config([
        'attachments.retry.max_attempts' => 3,
        'attachments.retry.backoff_seconds' => '60,300,900',
        'attachments.clamav.timeout_seconds' => 30,
    ]);
    $job = new ScanInboundAttachmentJob('attachment-id');
    expect($job->tries())->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900])
        ->and($job->timeout())->toBe(120)
        ->and(json_encode($job))->not->toContain('token');
});

it('leaves secure terminal state from the queue failed handler', function (): void {
    Storage::fake('attachments');
    $attachment = retryScanAttachment();
    Storage::disk('attachments')->put($attachment->storage_path, 'x');

    (new ScanInboundAttachmentJob((string) $attachment->id))->failed(new RuntimeException('secret path /var/clamav'));

    $fresh = $attachment->fresh();
    expect($fresh->scan_status)->toBe(AttachmentScanStatus::Failed)
        ->and($fresh->isQuarantined())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'attachment.scan_retry_exhausted')->exists())->toBeTrue()
        ->and(json_encode(AuditLog::query()->where('auditable_id', $attachment->id)->get()))->not->toContain('secret')
        ->not->toContain('/var/clamav');
});
