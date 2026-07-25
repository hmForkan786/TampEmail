<?php

declare(strict_types=1);

namespace App\Actions\Attachment;

use App\Enums\AttachmentScanStatus;
use App\Jobs\ScanInboundAttachmentJob;
use App\Models\Attachment;
use App\Models\User;
use App\Policies\AttachmentPolicy;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class RescanFailedAttachmentAction
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly AttachmentPolicy $policy,
    ) {}

    public function execute(User $actor, Attachment $attachment): Attachment
    {
        if (! $this->policy->rescan($actor, $attachment)) {
            throw new AuthorizationException('Only an active platform admin may rescan failed attachments.');
        }

        if ($attachment->scan_status !== AttachmentScanStatus::Failed) {
            throw new \DomainException('Only failed attachments may be rescanned.');
        }

        return DB::transaction(function () use ($actor, $attachment): Attachment {
            $locked = Attachment::query()->whereKey($attachment->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->trashed()) {
                throw new \DomainException('Attachment is unavailable for rescan.');
            }

            if ($locked->scan_status !== AttachmentScanStatus::Failed) {
                return $locked;
            }

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $attempt = (int) ($metadata['manual_rescan_count'] ?? 0) + 1;
            $metadata['manual_rescan_count'] = $attempt;
            $metadata['last_rescan_by'] = (string) $actor->getKey();
            $metadata['last_rescan_at'] = now()->toIso8601String();

            $claimed = Attachment::query()
                ->whereKey($locked->getKey())
                ->where('scan_status', AttachmentScanStatus::Failed)
                ->update([
                    'scan_status' => AttachmentScanStatus::Pending,
                    'is_safe' => null,
                    'scanned_at' => null,
                    'metadata' => $metadata,
                ]);

            if ($claimed !== 1) {
                return $locked->fresh() ?? $locked;
            }

            $refreshed = $locked->fresh() ?? $locked;

            $this->audit->write(
                'attachment.rescan_requested',
                (string) $actor->getKey(),
                $refreshed,
                ['scan_status' => AttachmentScanStatus::Failed->value],
                ['scan_status' => AttachmentScanStatus::Pending->value],
                [
                    'attachment_id' => (string) $refreshed->getKey(),
                    'email_id' => (string) $refreshed->email_id,
                    'requested_by' => (string) $actor->getKey(),
                    'manual_rescan_count' => $attempt,
                    'previous_status' => AttachmentScanStatus::Failed->value,
                    'scanner_backend' => (string) config('attachments.scanner_backend', 'disabled'),
                ],
            );

            ScanInboundAttachmentJob::dispatch((string) $refreshed->getKey())->afterCommit();

            return $refreshed;
        });
    }
}
