<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Concerns\StreamsSafeAttachments;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Policies\AttachmentVisibilityPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OutboundAttachmentDownloadController extends Controller
{
    use StreamsSafeAttachments;

    public function __construct(
        private readonly AttachmentVisibilityPolicy $attachmentVisibility,
    ) {}

    public function __invoke(Request $request, string $message, string $attachment): StreamedResponse|Response
    {
        /** @var User $owner */
        $owner = $request->user();

        $outbound = OutboundMessage::query()
            ->whereKey($message)
            ->where('user_id', $owner->getKey())
            ->first();

        if (
            $outbound === null
            || $outbound->source_email_id === null
            || ! in_array($attachment, $outbound->attachment_ids ?? [], true)
        ) {
            abort(404);
        }

        $record = Attachment::query()
            ->where('email_id', $outbound->source_email_id)
            ->whereKey($attachment)
            ->first();

        if ($record === null || ! $this->isSafeAttachmentRecord($record, $this->attachmentVisibility)) {
            abort(404);
        }

        $this->guardAgainstRangeRequests($request);

        return $this->streamAttachmentResponse($record);
    }
}
