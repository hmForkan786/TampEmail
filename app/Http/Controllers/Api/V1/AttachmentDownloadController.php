<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\User;
use App\Policies\AttachmentVisibilityPolicy;
use App\Services\Email\OwnedEmailVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttachmentDownloadController extends Controller
{
    public function __construct(
        private readonly OwnedEmailVisibilityService $visibility,
        private readonly AttachmentVisibilityPolicy $attachmentVisibility,
    ) {}

    public function __invoke(Request $request, string $inbox, string $email, string $attachment): StreamedResponse|Response
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');
        $ownedInbox = $this->visibility->resolveOwnedInbox($owner, $inbox);
        $ownedEmail = $this->visibility->findForInbox($ownedInbox, $email);
        $record = Attachment::query()
            ->where('email_id', $ownedEmail->getKey())
            ->whereKey($attachment)
            ->firstOrFail();

        if (! $this->isDownloadable($record, $request)) {
            abort(404);
        }

        $disk = Storage::disk($record->storage_disk);
        $filename = $this->safeFilename($record->original_filename);
        $mime = $this->safeMimeType($record->mime_type);

        return response()->streamDownload(function () use ($disk, $record): void {
            $stream = $disk->readStream($record->storage_path);
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
            'Content-Length' => (string) $record->size_bytes,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function isDownloadable(Attachment $attachment, Request $request): bool
    {
        if ($request->headers->has('Range')) {
            abort(416);
        }

        if ($attachment->storage_disk !== (string) config('platform.storage.attachments_disk')) {
            return false;
        }

        if ($attachment->size_bytes < 0 || $attachment->size_bytes > (int) config('attachments.max_bytes', 26214400)) {
            return false;
        }

        if (! $this->safeStoragePath($attachment->storage_path)) {
            return false;
        }

        return $this->attachmentVisibility->view(null, $attachment)
            && Storage::disk($attachment->storage_disk)->exists($attachment->storage_path);
    }

    private function safeStoragePath(string $path): bool
    {
        return $path !== ''
            && ! str_contains($path, "\0")
            && ! str_starts_with($path, '/')
            && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)
            && ! in_array('..', explode('/', str_replace('\\', '/', $path)), true);
    }

    private function safeFilename(?string $filename): string
    {
        $filename = preg_replace('/[\\x00-\\x1F\\x7F\\r\\n"\\\\\/]+/u', '_', (string) $filename) ?: 'attachment';
        $filename = trim($filename, " ._");

        return $filename !== '' ? mb_substr($filename, 0, 180) : 'attachment';
    }

    private function safeMimeType(?string $mime): string
    {
        return is_string($mime) && preg_match('/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+$/', $mime) === 1
            ? strtolower($mime)
            : 'application/octet-stream';
    }
}
