<?php

use App\DTOs\Inbound\InboundResolution;
use App\DTOs\Inbound\ParsedAttachment;
use App\DTOs\Inbound\ParsedInboundEmail;
use App\Actions\Inbound\IngestInboundEmailAction;
use App\Enums\InboundRoutingCode;
use App\Jobs\ScanInboundAttachmentJob;
use App\Models\Domain;
use App\Models\Inbox;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function dispatchFixture(): array
{
    $domain = Domain::query()->create(['domain'=>'dispatch-'.bin2hex(random_bytes(3)).'.test','display_name'=>'Dispatch','is_active'=>true,'is_public'=>true,'allow_registration'=>true,'is_healthy'=>true,'retention_hours'=>24]);
    $inbox = Inbox::query()->create(['domain_id'=>$domain->id,'local_part'=>'inbox','full_address'=>'inbox@'.$domain->domain,'inbox_type'=>'temporary','is_active'=>true]);
    $attachment = new ParsedAttachment('invoice.pdf','application/pdf','PDF-BYTES',10,hash('sha256','PDF-BYTES'),false,null);
    $parsed = new ParsedInboundEmail('dispatch-'.bin2hex(random_bytes(4)),'sender@example.test',$inbox->full_address,'Subject',Carbon::now(),[], 'text', '<p>text</p>', [$attachment], 100);
    $resolution = new InboundResolution(InboundRoutingCode::Resolved,$inbox->full_address,$domain->id,$inbox->id,null,true);
    return [$parsed, $resolution];
}

it('dispatches pending attachment scans after commit when enabled', function (): void {
    config(['attachments.scanner_backend'=>'clamav']); Storage::fake('attachments'); Queue::fake(); [$parsed,$resolution] = dispatchFixture();
    app(IngestInboundEmailAction::class)->execute($parsed,$resolution);
    Queue::assertPushed(ScanInboundAttachmentJob::class, 1);
    $job = Queue::pushed(ScanInboundAttachmentJob::class)[0];
    expect($job->attachmentId)->not->toBe('')->and(json_encode($job))->not->toContain('PDF-BYTES')->not->toContain('hash');
});

it('does not dispatch scans when backend is disabled', function (): void {
    config(['attachments.scanner_backend'=>'disabled']); Storage::fake('attachments'); Queue::fake(); [$parsed,$resolution] = dispatchFixture();
    app(IngestInboundEmailAction::class)->execute($parsed,$resolution);
    Queue::assertNothingPushed();
});

it('keeps ingestion successful without scanner completion', function (): void {
    config(['attachments.scanner_backend'=>'disabled']); Storage::fake('attachments'); Queue::fake(); [$parsed,$resolution] = dispatchFixture();
    $email = app(IngestInboundEmailAction::class)->execute($parsed,$resolution);
    expect($email->exists)->toBeTrue()->and($email->attachments()->first()->is_safe)->toBeNull();
});

it('does not dispatch a second job for an already clean attachment', function (): void {
    config(['attachments.scanner_backend'=>'disabled']); Storage::fake('attachments'); Queue::fake(); [$parsed,$resolution] = dispatchFixture();
    $email = app(IngestInboundEmailAction::class)->execute($parsed,$resolution);
    $attachment = $email->attachments()->first(); $attachment->update(['scan_status'=>'clean','is_safe'=>true]);
    expect($attachment->scan_status->value)->toBe('clean')->and($attachment->is_safe)->toBeTrue();
    Queue::assertNothingPushed();
});
