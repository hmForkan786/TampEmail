<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inbox\ListOwnedInboxesRequest;
use App\Http\Requests\Inbox\StoreOwnedInboxRequest;
use App\Http\Requests\Inbox\RenewOwnedInboxRequest;
use App\Actions\Inbox\CreateInboxAction;
use App\Actions\Inbox\DeleteInboxAction;
use App\Actions\Inbox\RenewInboxAction;
use App\DTOs\Inbox\CreateInboxData;
use App\DTOs\Inbox\InboxMutationContext;
use App\Exceptions\EligibleMailServerUnavailableException;
use App\Exceptions\InboxQuotaExceededException;
use App\Exceptions\InboxRenewalException;
use App\Models\Domain;
use App\Http\Resources\InboxCollection;
use App\Http\Resources\InboxResource;
use App\Models\Inbox;
use App\Models\User;
use App\Services\Inbox\OwnedInboxVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;
use App\Enums\InboxType;
use App\Http\Responses\ApiErrorResponse;

final class InboxController extends Controller
{
    public function __construct(
        private readonly OwnedInboxVisibilityService $visibility,
        private readonly CreateInboxAction $createInbox,
        private readonly DeleteInboxAction $deleteInbox,
        private readonly RenewInboxAction $renewInbox,
    ) {}

    public function store(StoreOwnedInboxRequest $request): InboxResource|JsonResponse
    {
        $owner = $this->owner($request);
        $domain = Domain::query()->active()->registrationAllowed()->whereKey($request->validated('domain_id'))->firstOrFail();
        $localPart = (string) $request->validated('local_part');
        $data = new CreateInboxData(
            domainId: (string) $domain->getKey(),
            userId: (string) $owner->getKey(),
            localPart: $localPart,
            fullAddress: strtolower($localPart).'@'.strtolower((string) $domain->domain),
            displayName: null,
            inboxType: InboxType::Temporary,
            expiresAt: $request->validated('expires_at')
                ? \Carbon\Carbon::parse($request->validated('expires_at'))
                : now()->addHours((int) config('inbox_lifetime.default_lifetime_hours', 0)),
            metadata: null,
        );
        try {
            $context = InboxMutationContext::forApi(
                (string) $owner->getKey(),
                (string) $request->attributes->get('apiKey')->getKey(),
            );
            return (new InboxResource($this->createInbox->execute($data, $owner, $context)))->response()->setStatusCode(201);
        } catch (InboxQuotaExceededException) {
            return ApiErrorResponse::make('inbox_quota_exceeded', 'Inbox quota exceeded.', 409);
        } catch (EligibleMailServerUnavailableException) {
            return ApiErrorResponse::make('mail_server_unavailable', 'No eligible mail server is available.', 503);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                return ApiErrorResponse::make('duplicate_inbox_address', 'The inbox address already exists.', 409);
            }
            throw $exception;
        }
    }

    public function index(ListOwnedInboxesRequest $request): InboxCollection
    {
        return InboxResource::collection($this->visibility->paginateForOwner($this->owner($request), $request->validated()));
    }

    public function show(Request $request, string $inbox): InboxResource
    {
        $record = $this->visibility->queryForOwner($this->owner($request))->whereKey($inbox)->firstOrFail();
        return new InboxResource($record);
    }

    public function destroy(Request $request, string $inbox): \Illuminate\Http\Response
    {
        $owner = $this->owner($request);
        $record = Inbox::query()->ownedBy((string) $owner->getKey())
            ->where('is_active', true)->whereKey($inbox)->firstOrFail();
        $this->deleteInbox->execute($record, InboxMutationContext::forApi(
            (string) $owner->getKey(),
            (string) $request->attributes->get('apiKey')->getKey(),
        ));

        return response()->noContent();
    }

    public function renew(RenewOwnedInboxRequest $request, string $inbox): InboxResource|JsonResponse
    {
        if (! config('inbox_lifetime.renewal_enabled', false)) return ApiErrorResponse::make('renewal_disabled', 'Inbox renewal is disabled.', 403);
        $owner = $this->owner($request);
        $record = Inbox::query()->ownedBy((string) $owner->getKey())->whereKey($inbox)->firstOrFail();
        try {
            $updated = $this->renewInbox->execute(
                $record,
                \Carbon\Carbon::parse($request->validated('expires_at')),
                $owner,
                InboxMutationContext::forApi((string) $owner->id, (string) $request->attributes->get('apiKey')->getKey()),
            );
            return new InboxResource($updated);
        } catch (InboxRenewalException $exception) {
            return ApiErrorResponse::make($exception->errorCode, $exception->getMessage(), $exception->errorCode === 'not_found' ? 404 : 422);
        }
    }

    private function owner(Request $request): User
    {
        return $request->attributes->get('apiKeyOwner');
    }
}
