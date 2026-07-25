<?php

declare(strict_types=1);

namespace App\Actions\Inbound;

use App\Actions\Attachment\RescanFailedAttachmentAction;
use App\Enums\ProcessingStage;
use App\Models\Attachment;
use App\Models\EmailProcessingLog;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Inbound\InboundMetricsRecorder;
use Illuminate\Auth\Access\AuthorizationException;

final class ReplayInboundFailureAction
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly InboundMetricsRecorder $metrics,
        private readonly RescanFailedAttachmentAction $rescan,
    ) {}

    public function execute(User $actor, EmailProcessingLog $failure): void
    {
        $this->metrics->record((string) $failure->email_id, 'replay_requested', ProcessingStage::Scan);
        if (! $actor->isPlatformAdmin()) {
            $this->metrics->record((string) $failure->email_id, 'replay_rejected');
            throw new AuthorizationException('Only an active platform admin may replay inbound failures.');
        }
        if ($failure->status->value !== 'failed') {
            $this->metrics->record((string) $failure->email_id, 'replay_rejected');
            throw new \DomainException('Only failed inbound items may be replayed.');
        }
        if ($failure->stage->value !== 'scan') {
            $this->metrics->record((string) $failure->email_id, 'replay_rejected');
            throw new \DomainException('Raw inbound replay is unavailable without retained raw MIME.');
        }

        $attachmentId = $failure->metadata['attachment_id'] ?? $failure->email?->attachments()->oldest()->value('id');
        if ($attachmentId === null) {
            $this->metrics->record((string) $failure->email_id, 'replay_rejected');
            throw new \DomainException('Replay target is missing.');
        }

        $attachment = Attachment::query()->find($attachmentId);
        if ($attachment === null) {
            $this->metrics->record((string) $failure->email_id, 'replay_rejected');
            throw new \DomainException('Replay target is missing.');
        }

        $this->rescan->execute($actor, $attachment);
        $this->audit->write('inbound.failure_replayed', (string) $actor->getKey(), $failure->email, null, null, ['target_id' => $failure->email_id, 'stage' => $failure->stage->value, 'source' => 'admin', 'attachment_id' => (string) $attachmentId]);
        $this->metrics->record((string) $failure->email_id, 'replayed', ProcessingStage::Scan);
    }
}
