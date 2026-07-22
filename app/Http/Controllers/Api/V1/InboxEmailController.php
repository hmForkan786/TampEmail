<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmailCollection;
use App\Http\Resources\EmailResource;
use App\Models\Email;
use App\Models\User;
use App\Services\Email\OwnedEmailVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class InboxEmailController extends Controller
{
    public function __construct(private readonly OwnedEmailVisibilityService $visibility) {}

    public function index(Request $request, string $inbox): EmailCollection
    {
        $owner = $this->owner($request);
        Gate::forUser($owner)->authorize('viewAny', Email::class);

        $resolved = $this->visibility->resolveOwnedInbox($owner, $inbox);
        $perPage = (int) $request->query('per_page', 15);

        return EmailResource::collection(
            $this->visibility->paginateForInbox($resolved, $perPage)
        );
    }

    public function show(Request $request, string $inbox, string $email): EmailResource
    {
        $owner = $this->owner($request);
        $resolved = $this->visibility->resolveOwnedInbox($owner, $inbox);
        $record = $this->visibility->findForInbox($resolved, $email);

        Gate::forUser($owner)->authorize('view', $record);

        return new EmailResource($record);
    }

    private function owner(Request $request): User
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');

        return $owner;
    }
}
