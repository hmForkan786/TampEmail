<?php
declare(strict_types=1);
namespace App\Policies;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
final class AttachmentVisibilityPolicy
{
    public function view(?\App\Models\User $user, Attachment $attachment): bool
    {
        return $attachment->scan_status?->value === 'clean' && $attachment->is_safe === true && config('filesystems.disks.'.$attachment->storage_disk.'.visibility') === 'private' && Storage::disk($attachment->storage_disk)->exists($attachment->storage_path);
    }
}
