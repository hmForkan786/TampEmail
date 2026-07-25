<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Attachment;
use App\Policies\AttachmentVisibilityPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared safe-download helpers for attachment streaming controllers.
 *
 * Centralizes path/filename/mime sanitization and the streamed response
 * shape so every attachment download endpoint enforces the same
 * guarantees: no path traversal, no storage-disk mismatch, no oversized
 * reads, and no raw storage paths leaked in headers, filenames, or errors.
 */
trait StreamsSafeAttachments
{
    protected function guardAgainstRangeRequests(Request $request): void
    {
        if ($request->headers->has('Range')) {
            abort(416);
        }
    }

    protected function isSafeAttachmentRecord(Attachment $attachment, AttachmentVisibilityPolicy $visibility): bool
    {
        if ($attachment->storage_disk !== (string) config('platform.storage.attachments_disk')) {
            return false;
        }

        if ($attachment->size_bytes < 0 || $attachment->size_bytes > (int) config('attachments.max_bytes', 26214400)) {
            return false;
        }

        if (! $this->safeStoragePath($attachment->storage_path)) {
            return false;
        }

        return $visibility->view(null, $attachment)
            && Storage::disk($attachment->storage_disk)->exists($attachment->storage_path);
    }

    protected function safeStoragePath(string $path): bool
    {
        return $path !== ''
            && ! str_contains($path, "\0")
            && ! str_starts_with($path, '/')
            && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)
            && ! in_array('..', explode('/', str_replace('\\', '/', $path)), true);
    }

    protected function safeFilename(?string $filename): string
    {
        $filename = preg_replace('/[\\x00-\\x1F\\x7F\\r\\n"\\\\\/]+/u', '_', (string) $filename) ?: 'attachment';
        $filename = trim($filename, ' ._');

        return $filename !== '' ? mb_substr($filename, 0, 180) : 'attachment';
    }

    protected function safeMimeType(?string $mime): string
    {
        return is_string($mime) && preg_match('/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+$/', $mime) === 1
            ? strtolower($mime)
            : 'application/octet-stream';
    }

    protected function streamAttachmentResponse(Attachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($attachment->storage_disk);
        $filename = $this->safeFilename($attachment->original_filename);
        $mime = $this->safeMimeType($attachment->mime_type);

        return response()->streamDownload(function () use ($disk, $attachment): void {
            $stream = $disk->readStream($attachment->storage_path);
            if (! is_resource($stream)) {
                return;
            }

            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $filename, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $attachment->size_bytes,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
