<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AttachmentScanStatus;
use App\Models\Attachment;
use App\Models\User;

/**
 * Administrative authorization for quarantined attachment review.
 *
 * Owner download visibility remains in AttachmentVisibilityPolicy.
 */
final class AttachmentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isPlatformAdmin();
    }

    public function view(User $actor, Attachment $attachment): bool
    {
        return $actor->isPlatformAdmin();
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, Attachment $attachment): bool
    {
        return false;
    }

    public function delete(User $actor, Attachment $attachment): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }

    public function restore(User $actor, Attachment $attachment): bool
    {
        return false;
    }

    public function restoreAny(User $actor): bool
    {
        return false;
    }

    public function forceDelete(User $actor, Attachment $attachment): bool
    {
        return false;
    }

    public function forceDeleteAny(User $actor): bool
    {
        return false;
    }

    public function rescan(User $actor, Attachment $attachment): bool
    {
        return $actor->isPlatformAdmin()
            && $attachment->scan_status === AttachmentScanStatus::Failed
            && $attachment->deleted_at === null;
    }

    public function permanentlyDelete(User $actor, Attachment $attachment): bool
    {
        return $actor->isPlatformAdmin()
            && in_array($attachment->scan_status, [AttachmentScanStatus::Infected, AttachmentScanStatus::Failed], true)
            && $attachment->deleted_at === null;
    }
}
