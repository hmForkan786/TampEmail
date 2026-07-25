<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\StreamsSafeAttachments;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Policies\AttachmentVisibilityPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams an attachment that was included on one of the caller's own
 * outbound messages. The attachment record still belongs to the source
 * `Email`; only messages that actually referenced it (via
 * `attachment_ids`) may download it, and only when it currently still
 * passes {@see AttachmentVisibilityPolicy}.
 */
final class OutboundAttachmentDownloadController extends Controller
{
    use StreamsSafeAttachments;

    public function __construct(
        private readonly AttachmentVisibilityPolicy $attachmentVisibility,
    ) {}

    public function __invoke(Request $request, string $message, string $attachment): StreamedResponse|Response
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');

        $outboundMessage = OutboundMessage::query()
            ->whereKey($message)
            ->where('user_id', $owner->getKey())
            ->first();

        if ($outboundMessage === null || $outboundMessage->source_email_id === null) {
            abort(404);
        }

        if (! in_array($attachment, $outboundMessage->attachment_ids ?? [], true)) {
            abort(404);
        }

        $record = Attachment::query()
            ->where('email_id', $outboundMessage->source_email_id)
            ->whereKey($attachment)
            ->first();

        if ($record === null) {
            abort(404);
        }

        $this->guardAgainstRangeRequests($request);

        if (! $this->isSafeAttachmentRecord($record, $this->attachmentVisibility)) {
            abort(404);
        }

        return $this->streamAttachmentResponse($record);
    }
}
