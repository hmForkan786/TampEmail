<?php

use App\Contracts\AttachmentScannerInterface;
use App\DTOs\Attachment\AttachmentScanRequest;
use App\DTOs\Attachment\AttachmentScanResultData;
use App\Enums\AttachmentScanResult;
use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Models\Email;
use App\Models\Domain;
use App\Models\Inbox;
use App\Services\Inbound\AttachmentScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function scanAttachment(array $overrides = []): Attachment
{
    $domain = Domain::query()->create(['domain'=>'scan-'.bin2hex(random_bytes(3)).'.test','display_name'=>'Scan','is_active'=>true,'is_public'=>true,'allow_registration'=>true,'is_healthy'=>true,'retention_hours'=>24]);
    $inbox = Inbox::query()->create(['domain_id'=>$domain->id,'local_part'=>'scan','full_address'=>'scan@'.$domain->domain,'inbox_type'=>'temporary','is_active'=>true]);
    $email = Email::query()->create(['inbox_id'=>$inbox->id,'message_id'=>'scan-'.bin2hex(random_bytes(4)),'sender_email'=>'a@example.test','recipient_email'=>$inbox->full_address,'received_at'=>now(),'size_bytes'=>3,'processing_status'=>'received']);
    return Attachment::query()->create(array_merge(['email_id'=>$email->id,'original_filename'=>'file.txt','stored_filename'=>'opaque','mime_type'=>'text/plain','size_bytes'=>3,'checksum_sha256'=>hash('sha256','abc'),'storage_disk'=>'attachments','storage_path'=>'quarantine/'.$email->id.'/opaque','scan_status'=>AttachmentScanStatus::Pending,'is_safe'=>null], $overrides));
}

it('keeps disabled scanner attachments pending and blocks visibility', function (): void {
    config(['attachments.scanner_backend' => 'disabled']); Storage::fake('attachments');
    $attachment = scanAttachment(); $result = app(AttachmentScanService::class)->scan($attachment);
    expect($result->scan_status)->toBe(AttachmentScanStatus::Pending)->and($result->is_safe)->toBeNull()->and((new App\Policies\AttachmentVisibilityPolicy)->view(null, $result))->toBeFalse();
});

it('accepts only an explicit clean scanner result', function (): void {
    config(['attachments.scanner_backend' => 'clamav']); Storage::fake('attachments');
    $attachment = scanAttachment(); Storage::disk('attachments')->put($attachment->storage_path, 'abc');
    app()->instance(AttachmentScannerInterface::class, new class implements AttachmentScannerInterface { public function scan(AttachmentScanRequest $request): AttachmentScanResultData { return new AttachmentScanResultData(AttachmentScanResult::Clean, scannerVersion: 'test'); } });
    $result = app(AttachmentScanService::class)->scan($attachment);
    expect($result->scan_status)->toBe(AttachmentScanStatus::Clean)->and($result->is_safe)->toBeTrue();
});

it('marks scanner errors failed and infected attachments unsafe', function (): void {
    config(['attachments.scanner_backend' => 'clamav']); Storage::fake('attachments');
    $attachment = scanAttachment(); Storage::disk('attachments')->put($attachment->storage_path, 'abc');
    app()->instance(AttachmentScannerInterface::class, new class implements AttachmentScannerInterface { public function scan(AttachmentScanRequest $request): AttachmentScanResultData { return new AttachmentScanResultData(AttachmentScanResult::Infected, 'test-signature'); } });
    $result = app(AttachmentScanService::class)->scan($attachment);
    expect($result->scan_status)->toBe(AttachmentScanStatus::Infected)->and($result->is_safe)->toBeFalse();
});
