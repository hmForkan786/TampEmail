<?php

use App\DTOs\Attachment\AttachmentScanRequest;
use App\DTOs\Attachment\AttachmentScanResultData;
use App\DTOs\Inbound\InboundResolution;
use App\DTOs\Inbound\ParsedAttachment;
use App\DTOs\Inbound\ParsedInboundEmail;
use App\Enums\AttachmentScanResult;
use App\Enums\AttachmentScanStatus;
use App\Enums\InboundRoutingCode;
use App\Jobs\ScanInboundAttachmentJob;
use App\Models\Attachment;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\Email;
use App\Models\EmailProcessingLog;
use App\Contracts\AttachmentScannerInterface;
use App\Services\Inbound\AttachmentScanService;
use App\Actions\Inbound\IngestInboundEmailAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Queue\ShouldBeUnique;

uses(RefreshDatabase::class);

function rollbackFixture(): array
{
    $domain = Domain::query()->create(['domain'=>'rollback-'.bin2hex(random_bytes(3)).'.test','display_name'=>'Rollback','is_active'=>true,'is_public'=>true,'allow_registration'=>true,'is_healthy'=>true,'retention_hours'=>24]);
    $inbox = Inbox::query()->create(['domain_id'=>$domain->id,'local_part'=>'inbox','full_address'=>'inbox@'.$domain->domain,'inbox_type'=>'temporary','is_active'=>true]);
    $attachment = new ParsedAttachment('payload.bin','application/octet-stream','PRIVATE-BYTES',13,hash('sha256','PRIVATE-BYTES'),false,null);
    $parsed = new ParsedInboundEmail('rollback-'.bin2hex(random_bytes(4)),'from@example.test',$inbox->full_address,'Rollback',Carbon::now(),[], 'body', null, [$attachment], 13);
    return [$parsed, new InboundResolution(InboundRoutingCode::Resolved,$inbox->full_address,$domain->id,$inbox->id,null,true)];
}

it('keeps terminal failed and infected attachments from automatic rescans', function (): void {
    Storage::fake('attachments'); $email = Email::query()->create(['inbox_id'=>rollbackFixture()[1]->inboxId,'message_id'=>'state-'.bin2hex(random_bytes(3)),'sender_email'=>'a@test','recipient_email'=>'b@test','received_at'=>now(),'size_bytes'=>1,'processing_status'=>'received']);
    foreach ([AttachmentScanStatus::Failed, AttachmentScanStatus::Infected, AttachmentScanStatus::Clean] as $status) {
        $a = Attachment::query()->create(['email_id'=>$email->id,'original_filename'=>'x','stored_filename'=>'x','mime_type'=>'text/plain','size_bytes'=>1,'checksum_sha256'=>hash('sha256','x'),'storage_disk'=>'attachments','storage_path'=>'missing','scan_status'=>$status,'is_safe'=>$status === AttachmentScanStatus::Clean]);
        $result = app(AttachmentScanService::class)->scan($a); expect($result->scan_status)->toBe($status);
    }
});

it('records retry exhaustion as failed with safe processing metadata', function (): void {
    $fixture = rollbackFixture(); $email = app(IngestInboundEmailAction::class)->execute($fixture[0], $fixture[1]); $attachment = $email->attachments()->first();
    (new ScanInboundAttachmentJob($attachment->id))->failed(new RuntimeException('secret scanner command must not persist'));
    $attachment->refresh(); $log = EmailProcessingLog::query()->where('email_id',$email->id)->latest('created_at')->first();
    expect($attachment->scan_status)->toBe(AttachmentScanStatus::Failed)->and($attachment->is_safe)->toBeNull()
        ->and($log->metadata['error_code'])->toBe('retry_exhausted')
        ->and($log->metadata['failure_code'])->toBe('attachment_scan_retry_exhausted')
        ->and($log->metadata['attempts'])->toBe(1)
        ->and(json_encode($log))->not->toContain('secret');
});

it('cleans quarantine files and rolls back email metadata when storage fails', function (): void {
    config(['attachments.scanner_backend'=>'disabled']); Queue::fake(); Storage::fake('attachments');
    Storage::shouldReceive('disk')->andThrow(new RuntimeException('storage unavailable'));
    $fixture = rollbackFixture();
    expect(fn () => app(IngestInboundEmailAction::class)->execute($fixture[0], $fixture[1]))->toThrow(RuntimeException::class);
    expect(Email::query()->count())->toBe(0)->and(Attachment::query()->count())->toBe(0)->and(Queue::pushed(ScanInboundAttachmentJob::class))->toBeEmpty();
});

it('does not dispatch a missing quarantine attachment and marks it failed', function (): void {
    config(['attachments.scanner_backend'=>'clamav']); Storage::fake('attachments'); Queue::fake();
    $fixture = rollbackFixture();
    $email = app(IngestInboundEmailAction::class)->execute($fixture[0], $fixture[1]);
    $attachment = $email->attachments()->first();
    Queue::fake();
    $attachment->update(['storage_path'=>'missing/quarantine.bin']);
    $result = app(AttachmentScanService::class)->scan($attachment->fresh());
    expect($result->scan_status)->toBe(AttachmentScanStatus::Failed)
        ->and(Queue::pushed(ScanInboundAttachmentJob::class))->toBeEmpty();
});

it('treats scanner exceptions as retryable failed state without unsafe details', function (): void {
    config(['attachments.scanner_backend'=>'clamav']); Storage::fake('attachments');
    $fixture = rollbackFixture();
    $email = app(IngestInboundEmailAction::class)->execute($fixture[0], $fixture[1]);
    $attachment = $email->attachments()->first();
    app()->bind(AttachmentScannerInterface::class, fn () => new class implements AttachmentScannerInterface {
        public function scan(AttachmentScanRequest $request): AttachmentScanResultData { throw new RuntimeException('private scanner command'); }
    });
    $result = app(AttachmentScanService::class)->scan($attachment);
    expect($result->scan_status)->toBe(AttachmentScanStatus::Failed)->and($result->is_safe)->toBeNull()
        ->and(json_encode($result))->not->toContain('private scanner command');
});

it('exposes job uniqueness without placing attachment bytes in its payload', function (): void {
    $job = new ScanInboundAttachmentJob('attachment-id');
    expect($job)->toBeInstanceOf(ShouldBeUnique::class)->and($job->uniqueId())->toBe('attachment-id')
        ->and($job->backoff())->toBe([60, 300, 900])
        ->and(json_encode($job))->not->toContain('token')->not->toContain('hash')->not->toContain('command');
});

it('isolates scan failure between multiple attachments', function (): void {
    config(['attachments.scanner_backend'=>'clamav']); Storage::fake('attachments'); Queue::fake();
    [$parsed, $resolution] = rollbackFixture();
    $parsed = new ParsedInboundEmail($parsed->messageId, $parsed->senderEmail, $parsed->recipientEmail, $parsed->subject, $parsed->receivedAt, $parsed->headers, $parsed->textBody, $parsed->htmlBody, [
        ...$parsed->attachments,
        new ParsedAttachment('second.bin','application/octet-stream','SECOND-BYTES',13,hash('sha256','SECOND-BYTES'),false,null),
    ], 26);
    $email = app(IngestInboundEmailAction::class)->execute($parsed, $resolution);
    expect($email->attachments()->count())->toBe(2)->and(Queue::pushed(ScanInboundAttachmentJob::class))->toHaveCount(2);
    $attachments = $email->attachments()->get();
    $attachments[0]->update(['scan_status'=>AttachmentScanStatus::Failed,'is_safe'=>null]);
    $attachments[1]->update(['scan_status'=>AttachmentScanStatus::Infected,'is_safe'=>false]);
    expect($attachments[0]->fresh()->scan_status)->toBe(AttachmentScanStatus::Failed)
        ->and($attachments[1]->fresh()->scan_status)->toBe(AttachmentScanStatus::Infected);
});
