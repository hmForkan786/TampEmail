<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmailResource;
use App\Models\Email;
use App\Models\User;
use App\Services\Email\OwnedEmailVisibilityService;
use Illuminate\Http\Request;

final class EmailReadStateController extends Controller
{
    public function __construct(private readonly OwnedEmailVisibilityService $visibility) {}

    public function read(Request $request, string $inbox, string $email): EmailResource
    {
        return $this->change($request, $inbox, $email, true);
    }

    public function unread(Request $request, string $inbox, string $email): EmailResource
    {
        return $this->change($request, $inbox, $email, false);
    }

    private function change(Request $request, string $inbox, string $email, bool $isRead): EmailResource
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');
        $ownedInbox = $this->visibility->resolveOwnedInbox($owner, $inbox);
        $record = $this->visibility->findForInbox($ownedInbox, $email);

        $readAt = $isRead ? now() : null;
        if ($record->is_read !== $isRead || ($isRead && $record->read_at === null) || (! $isRead && $record->read_at !== null)) {
            $record->forceFill([
                'is_read' => $isRead,
                'read_at' => $readAt,
            ])->save();
        }

        $record->refresh()->load(['body', 'attachments', 'inbox']);

        return new EmailResource($record);
    }
}
