<?php
declare(strict_types=1);
namespace App\Actions\Inbound;
use App\Jobs\ScanInboundAttachmentJob;
use App\Models\EmailProcessingLog;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
final class ReplayInboundFailureAction
{
    public function __construct(private readonly AuditLogWriter $audit) {}
    public function execute(User $actor, EmailProcessingLog $failure): void
    {
        if (! $actor->isPlatformAdmin()) throw new AuthorizationException('Only an active platform admin may replay inbound failures.');
        if ($failure->status->value !== 'failed') throw new \DomainException('Only failed inbound items may be replayed.');
        if ($failure->stage->value !== 'scan') throw new \DomainException('Raw inbound replay is unavailable without retained raw MIME.');
        DB::transaction(function () use ($actor, $failure): void {
            $attachmentId = $failure->metadata['attachment_id'] ?? $failure->email?->attachments()->oldest()->value('id');
            if ($attachmentId === null) throw new \DomainException('Replay target is missing.');
            ScanInboundAttachmentJob::dispatch((string) $attachmentId)->afterCommit();
            $this->audit->write('inbound.failure_replayed',(string)$actor->getKey(),$failure->email,null,null,['target_id'=>$failure->email_id,'stage'=>$failure->stage->value,'source'=>'admin']);
        });
    }
}
