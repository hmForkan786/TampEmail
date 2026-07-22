<?php
use App\Actions\Inbound\ReplayInboundFailureAction;
use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Jobs\ScanInboundAttachmentJob;
use App\Models\Attachment;
use App\Models\Domain;
use App\Models\Email;
use App\Models\EmailProcessingLog;
use App\Models\Inbox;
use App\Services\Inbound\InboundFailureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
uses(RefreshDatabase::class);

function dlqEmail(): Email
{
    $domain = Domain::query()->create(['domain'=>'dlq-'.bin2hex(random_bytes(3)).'.test','display_name'=>'DLQ','is_active'=>true,'is_public'=>true,'allow_registration'=>true,'is_healthy'=>true,'retention_hours'=>24]);
    $inbox = Inbox::query()->create(['domain_id'=>$domain->id,'local_part'=>'inbox','full_address'=>'inbox@'.$domain->domain,'inbox_type'=>'temporary','is_active'=>true]);
    return Email::query()->create(['inbox_id'=>$inbox->id,'message_id'=>'dlq-'.bin2hex(random_bytes(4)),'sender_email'=>'sender@test','recipient_email'=>$inbox->full_address,'received_at'=>now(),'size_bytes'=>1,'processing_status'=>'received']);
}

it('records one redacted failure record for repeated terminal failures', function (): void {
    $email = dlqEmail(); $service = app(InboundFailureService::class);
    $first = $service->record($email->id, ProcessingStage::Scan, 'attachment_scan_retry_exhausted', 3, ['error_code'=>'retry_exhausted','token'=>'never']);
    $second = $service->record($email->id, ProcessingStage::Scan, 'attachment_scan_retry_exhausted', 4, ['command'=>'never']);
    expect($first->id)->toBe($second->id)->and(EmailProcessingLog::query()->count())->toBe(1)
        ->and(json_encode($first))->not->toContain('never');
});

it('allows only an active admin to replay a failed attachment after commit', function (): void {
    $email = dlqEmail();
    $attachment = Attachment::query()->create(['email_id'=>$email->id,'original_filename'=>'x.bin','stored_filename'=>'x','mime_type'=>'application/octet-stream','size_bytes'=>1,'checksum_sha256'=>hash('sha256','x'),'storage_disk'=>'attachments','storage_path'=>'x','scan_status'=>'failed','is_safe'=>null]);
    $failure = EmailProcessingLog::query()->create(['email_id'=>$email->id,'stage'=>ProcessingStage::Scan,'status'=>ProcessingLogStatus::Failed,'worker'=>'test','duration_ms'=>0,'metadata'=>['failure_code'=>'attachment_scan_retry_exhausted','attempts'=>3]]);
    $operator = App\Models\User::factory()->platformOperator()->create(); $admin = App\Models\User::factory()->platformAdmin()->create();
    expect(fn () => app(ReplayInboundFailureAction::class)->execute($operator,$failure))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
    Queue::fake(); app(ReplayInboundFailureAction::class)->execute($admin,$failure);
    Queue::assertPushed(ScanInboundAttachmentJob::class, fn ($job) => $job->attachmentId === (string)$attachment->id);
    expect(App\Models\AuditLog::query()->where('action','inbound.failure_replayed')->exists())->toBeTrue();
});

it('rejects ingestion replay because raw MIME is not retained', function (): void {
    $email = dlqEmail();
    $failure = EmailProcessingLog::query()->create(['email_id'=>$email->id,'stage'=>ProcessingStage::Parse,'status'=>ProcessingLogStatus::Failed,'worker'=>'test','duration_ms'=>0,'metadata'=>['failure_code'=>'inbound_retry_exhausted','attempts'=>3]]);
    $admin = App\Models\User::factory()->platformAdmin()->create();
    expect(fn () => app(ReplayInboundFailureAction::class)->execute($admin,$failure))->toThrow(DomainException::class);
    expect(App\Models\AuditLog::query()->count())->toBe(0);
});
