<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Exceptions\OutboundSendException;
use App\Models\Attachment;
use App\Models\Email;
use App\Policies\AttachmentVisibilityPolicy;
use Illuminate\Support\Collection;

final class OutboundAttachmentSelector
{
    public function __construct(
        private readonly AttachmentVisibilityPolicy $visibility,
    ) {}

    /**
     * @param  list<string>  $attachmentIds
     * @return list<Attachment>
     */
    public function selectForForward(Email $email, array $attachmentIds, bool $recheckStorage = true): array
    {
        $attachmentIds = array_values(array_unique(array_filter($attachmentIds, fn ($id): bool => is_string($id) && $id !== '')));

        if ($attachmentIds === []) {
            return [];
        }

        $maxCount = (int) config('outbound.max_attachments_per_message', 10);
        if (count($attachmentIds) > $maxCount) {
            throw new OutboundSendException('attachments_limit', "A forward may include at most {$maxCount} attachments.", 422);
        }

        /** @var Collection<int, Attachment> $found */
        $found = Attachment::query()
            ->where('email_id', $email->getKey())
            ->whereIn('id', $attachmentIds)
            ->get()
            ->keyBy(fn (Attachment $attachment): string => (string) $attachment->getKey());

        $selected = [];
        $totalBytes = 0;
        $maxFile = (int) config('outbound.max_attachment_bytes', 10485760);
        $maxTotal = (int) config('outbound.max_total_attachment_bytes', 26214400);

        foreach ($attachmentIds as $id) {
            $attachment = $found->get($id);
            if ($attachment === null) {
                throw new OutboundSendException('attachment_not_found', 'One or more selected attachments are not part of the original email.', 422);
            }

            if ($attachment->trashed()) {
                throw new OutboundSendException('attachment_deleted', 'A selected attachment has been deleted.', 422);
            }

            if (! $this->visibility->mayIncludeInOutgoing($attachment)) {
                throw new OutboundSendException('attachment_unsafe', 'Only clean, safe attachments may be forwarded.', 422);
            }

            if ($recheckStorage && ! $this->visibility->view(null, $attachment)) {
                throw new OutboundSendException('attachment_unavailable', 'A selected attachment is no longer available.', 422);
            }

            if ((int) $attachment->size_bytes > $maxFile) {
                throw new OutboundSendException('attachment_too_large', 'A selected attachment exceeds the per-file size limit.', 422);
            }

            $totalBytes += (int) $attachment->size_bytes;
            if ($totalBytes > $maxTotal) {
                throw new OutboundSendException('attachments_total_too_large', 'Selected attachments exceed the total size limit.', 422);
            }

            $selected[] = $attachment;
        }

        return $selected;
    }

    /**
     * @param  list<Attachment>  $attachments
     * @return list<array{filename: string, mime_type: string, size_bytes: int, storage_disk: string, storage_path: string}>
     */
    public function toTransportPayload(array $attachments): array
    {
        $usedNames = [];
        $payload = [];

        foreach ($attachments as $attachment) {
            $filename = $this->safeFilename((string) $attachment->original_filename, $usedNames);
            $usedNames[] = mb_strtolower($filename);

            $payload[] = [
                'filename' => $filename,
                'mime_type' => $this->safeMime((string) $attachment->mime_type),
                'size_bytes' => (int) $attachment->size_bytes,
                'storage_disk' => (string) $attachment->storage_disk,
                'storage_path' => (string) $attachment->storage_path,
            ];
        }

        return $payload;
    }

    /**
     * @param  list<string>  $usedNames
     */
    private function safeFilename(string $filename, array $usedNames): string
    {
        $filename = str_replace(["\0", '/', '\\'], '', $filename);
        $filename = preg_replace('/[\r\n\t]+/', ' ', $filename) ?? $filename;
        $filename = trim($filename);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = 'attachment.bin';
        }
        $filename = mb_substr($filename, 0, 180);

        $candidate = $filename;
        $i = 1;
        while (in_array(mb_strtolower($candidate), $usedNames, true)) {
            $candidate = $i.'_'.$filename;
            $i++;
        }

        return $candidate;
    }

    private function safeMime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        if ($mime === '' || preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/', $mime) !== 1) {
            return 'application/octet-stream';
        }

        return mb_substr($mime, 0, 100);
    }
}
