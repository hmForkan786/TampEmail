<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class AttachmentVisibilityPolicy
{
    public function view(?User $user, Attachment $attachment): bool
    {
        return $attachment->scan_status?->value === 'clean'
            && $attachment->is_safe === true
            && config('filesystems.disks.'.$attachment->storage_disk.'.visibility') === 'private'
            && Storage::disk($attachment->storage_disk)->exists($attachment->storage_path);
    }

    public function isQuarantined(Attachment $attachment): bool
    {
        return in_array($attachment->scan_status, [AttachmentScanStatus::Infected, AttachmentScanStatus::Failed], true);
    }

    public function mayIncludeInOutgoing(Attachment $attachment): bool
    {
        return $this->view(null, $attachment);
    }
}
