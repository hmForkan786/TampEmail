<?php

declare(strict_types=1);

namespace App\Actions\Attachment;

use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Models\User;
use App\Policies\AttachmentPolicy;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PermanentlyDeleteQuarantinedAttachmentAction
{
    public function __construct(
        private readonly AuditLogWriter $audit,
        private readonly AttachmentPolicy $policy,
    ) {}

    public function execute(User $actor, Attachment $attachment): Attachment
    {
        if ($attachment->trashed()) {
            return $attachment;
        }

        if (! $this->policy->permanentlyDelete($actor, $attachment)) {
            throw new AuthorizationException('Only an active platform admin may permanently delete quarantined attachments.');
        }

        if (! in_array($attachment->scan_status, [AttachmentScanStatus::Infected, AttachmentScanStatus::Failed], true)) {
            throw new \DomainException('Only infected or failed attachments may be permanently deleted.');
        }

        if ($attachment->inboundHolds()->active()->exists()) {
            throw new \DomainException('Attachment is held and cannot be permanently deleted.');
        }

        return DB::transaction(function () use ($actor, $attachment): Attachment {
            $locked = Attachment::query()->whereKey($attachment->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                $existing = Attachment::withTrashed()->whereKey($attachment->getKey())->first();
                if ($existing !== null && $existing->trashed()) {
                    return $existing;
                }

                throw new \DomainException('Attachment is unavailable for deletion.');
            }

            if (! in_array($locked->scan_status, [AttachmentScanStatus::Infected, AttachmentScanStatus::Failed], true)) {
                throw new \DomainException('Only infected or failed attachments may be permanently deleted.');
            }

            if ($locked->inboundHolds()->active()->exists()) {
                throw new \DomainException('Attachment is held and cannot be permanently deleted.');
            }

            if (! $this->safeQuarantinePath($locked->storage_path)) {
                throw new \DomainException('Unsafe attachment storage path.');
            }

            $objectMissing = true;
            $disk = Storage::disk($locked->storage_disk);
            if ($disk->exists($locked->storage_path)) {
                if (! $disk->delete($locked->storage_path)) {
                    throw new \RuntimeException('Attachment storage deletion failed.');
                }
                $objectMissing = false;
            }

            $previousStatus = $locked->scan_status->value;
            $locked->delete();

            $this->audit->write(
                'attachment.quarantine_purged',
                (string) $actor->getKey(),
                $locked,
                ['scan_status' => $previousStatus, 'deleted' => false],
                ['deleted' => true],
                [
                    'attachment_id' => (string) $locked->getKey(),
                    'email_id' => (string) $locked->email_id,
                    'previous_status' => $previousStatus,
                    'storage_disk' => $locked->storage_disk,
                    'object_missing' => $objectMissing,
                    'purged_by' => (string) $actor->getKey(),
                    'purged_at' => now()->toIso8601String(),
                ],
            );

            return Attachment::withTrashed()->whereKey($locked->getKey())->first() ?? $locked;
        });
    }

    private function safeQuarantinePath(string $path): bool
    {
        return $path !== ''
            && ! str_contains($path, "\0")
            && ! str_contains($path, '..')
            && ! str_starts_with($path, '/')
            && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)
            && str_starts_with($path, 'quarantine/');
    }
}
