<?php

use App\Contracts\AttachmentScannerInterface;
use App\DTOs\Attachment\AttachmentScanRequest;
use App\Enums\AttachmentScanResult;
use App\Services\Inbound\ClamAvAttachmentScanner;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

it('fails closed when the configured ClamAV service is unavailable', function (): void {
    config(['attachments.scanner_backend' => 'clamav', 'attachments.clamav.host' => '127.0.0.1', 'attachments.clamav.port' => 1, 'attachments.clamav.connect_timeout_seconds' => 1]);
    Storage::fake('attachments');
    Storage::disk('attachments')->put('sample.bin', 'safe fixture bytes');

    $result = app(ClamAvAttachmentScanner::class)->scan(new AttachmentScanRequest('attachments', 'sample.bin', 18, hash('sha256', 'safe fixture bytes'), 'application/octet-stream'));

    expect($result->result)->toBe(AttachmentScanResult::Failed)->and($result->scannerVersion)->toBe('clamav:unavailable');
});

it('rejects an attachment larger than the configured scan limit before connecting', function (): void {
    config(['attachments.max_bytes' => 4]);
    $scanner = app(ClamAvAttachmentScanner::class);

    $result = $scanner->scan(new AttachmentScanRequest('attachments', 'not-read', 5, 'checksum', 'application/octet-stream'));

    expect($result->result)->toBe(AttachmentScanResult::Failed)->and($result->scannerVersion)->toBe('clamav:limit');
});

it('keeps the disabled scanner contract non-clean', function (): void {
    config(['attachments.scanner_backend' => 'disabled']);
    $scanner = app(AttachmentScannerInterface::class);
    $result = $scanner->scan(new AttachmentScanRequest('attachments', 'unused', 0, '', 'application/octet-stream'));

    expect($result->result)->toBe(AttachmentScanResult::Failed);
});

it('AttachmentScannerHealth reports deterministic safe output without external ClamAV', function (): void {
    config(['attachments.scanner_backend' => 'disabled']);
    expect(Artisan::call('attachments:scanner-health', ['--json' => true]))->toBe(2)
        ->and(json_decode(Artisan::output(), true)['status'])->toBe('disabled')
        ->and(Artisan::call('attachments:scanner-health'))->toBe(2)
        ->and(Artisan::output())->toContain('status: disabled')->not->toContain('127.0.0.1');
});
