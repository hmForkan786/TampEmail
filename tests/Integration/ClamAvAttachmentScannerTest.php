<?php

use App\DTOs\Attachment\AttachmentScanRequest;
use App\Enums\AttachmentScanResult;
use App\Services\Inbound\ClamAvAttachmentScanner;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    if ((string) env('RUN_CLAMAV_TESTS', '0') !== '1') {
        $this->markTestSkipped('ClamAV integration tests are disabled; set RUN_CLAMAV_TESTS=1.');
    }

    $host = (string) env('ATTACHMENT_CLAMAV_HOST', '127.0.0.1');
    $port = (int) env('ATTACHMENT_CLAMAV_PORT', 3310);
    $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 2);
    if (! is_resource($socket)) {
        $this->fail("RUN_CLAMAV_TESTS=1 but ClamAV is unavailable at {$host}:{$port} ({$errorMessage}).");
    }
    fclose($socket);
    config(['attachments.scanner_backend' => 'clamav']);
    Storage::fake('attachments');
});

it('returns clean for a harmless fixture', function (): void {
    Storage::disk('attachments')->put('integration-clean.txt', 'clamav integration clean fixture');
    $result = app(ClamAvAttachmentScanner::class)->scan(new AttachmentScanRequest('attachments', 'integration-clean.txt', 33, hash('sha256', 'clamav integration clean fixture'), 'text/plain'));
    expect($result->result)->toBe(AttachmentScanResult::Clean);
});

it('returns infected for the standard EICAR test fixture', function (): void {
    $eicar = 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
    Storage::disk('attachments')->put('integration-eicar.txt', $eicar);
    $result = app(ClamAvAttachmentScanner::class)->scan(new AttachmentScanRequest('attachments', 'integration-eicar.txt', strlen($eicar), hash('sha256', $eicar), 'text/plain'));
    expect($result->result)->toBe(AttachmentScanResult::Infected)->and($result->signature)->toBeString()->and(strlen($result->signature))->toBeLessThanOrEqual(120);
});
