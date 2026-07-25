<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\StreamsSafeAttachments;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\User;
use App\Policies\AttachmentVisibilityPolicy;
use App\Services\Email\OwnedEmailVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttachmentDownloadController extends Controller
{
    use StreamsSafeAttachments;

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

        $this->guardAgainstRangeRequests($request);

        if (! $this->isSafeAttachmentRecord($record, $this->attachmentVisibility)) {
            abort(404);
        }

        return $this->streamAttachmentResponse($record);
    }
}
