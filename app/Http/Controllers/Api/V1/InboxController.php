<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inbox\CreateInboxAction;
use App\Actions\Inbox\DeleteInboxAction;
use App\Actions\Inbox\RenewInboxAction;
use App\DTOs\Inbox\CreateInboxData;
use App\DTOs\Inbox\InboxMutationContext;
use App\Enums\InboxType;
use App\Exceptions\CommercialEntitlementDeniedException;
use App\Exceptions\EligibleMailServerUnavailableException;
use App\Exceptions\InboxRenewalException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inbox\ListOwnedInboxesRequest;
use App\Http\Requests\Inbox\RenewOwnedInboxRequest;
use App\Http\Requests\Inbox\StoreOwnedInboxRequest;
use App\Http\Resources\InboxResource;
use App\Http\Responses\ApiErrorResponse;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\User;
use App\Services\Entitlement\EntitlementService;
use App\Services\Inbox\OwnedInboxVisibilityService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class InboxController extends Controller
{
    public function __construct(
        private readonly OwnedInboxVisibilityService $visibility,
        private readonly CreateInboxAction $createInbox,
        private readonly DeleteInboxAction $deleteInbox,
        private readonly RenewInboxAction $renewInbox,
        private readonly EntitlementService $entitlements,
    ) {}

    public function store(StoreOwnedInboxRequest $request): JsonResponse
    {
        $owner = $this->owner($request);
        $domain = Domain::query()->active()->registrationAllowed()->whereKey($request->validated('domain_id'))->firstOrFail();
        $submittedLocalPart = $request->validated('local_part');
        if (is_string($submittedLocalPart) && $submittedLocalPart !== '' && ! $this->entitlements->allows($owner, 'inbox.custom_alias')) {
            return $this->commercialDenial(new CommercialEntitlementDeniedException('inbox.custom_alias'));
        }
        $localPart = is_string($submittedLocalPart) && $submittedLocalPart !== ''
            ? $submittedLocalPart
            : $this->generatedLocalPart((string) $domain->domain);
        $retentionHours = $this->entitlements->limit($owner, 'inbox.retention_hours');
        if ($retentionHours < 1) {
            return $this->commercialDenial(new CommercialEntitlementDeniedException('inbox.retention_hours'));
        }
        $retentionExpiresAt = now()->addHours($retentionHours);
        $requestedExpiresAt = $request->validated('expires_at')
            ? Carbon::parse($request->validated('expires_at'))
            : null;
        $data = new CreateInboxData(
            domainId: (string) $domain->getKey(),
            userId: (string) $owner->getKey(),
            localPart: $localPart,
            fullAddress: strtolower($localPart).'@'.strtolower((string) $domain->domain),
            displayName: null,
            inboxType: InboxType::Temporary,
            expiresAt: $requestedExpiresAt !== null && $requestedExpiresAt->lt($retentionExpiresAt)
                ? $requestedExpiresAt
                : $retentionExpiresAt,
            metadata: null,
        );
        try {
            $context = InboxMutationContext::forApi(
                (string) $owner->getKey(),
                (string) $request->attributes->get('apiKey')->getKey(),
            );

            return (new InboxResource($this->createInbox->execute($data, $owner, $context)))->response()->setStatusCode(201);
        } catch (CommercialEntitlementDeniedException $exception) {
            return $this->commercialDenial($exception);
        } catch (EligibleMailServerUnavailableException) {
            return ApiErrorResponse::make('mail_server_unavailable', 'No eligible mail server is available.', 503);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                return ApiErrorResponse::make('duplicate_inbox_address', 'The inbox address already exists.', 409);
            }
            throw $exception;
        }
    }

    public function index(ListOwnedInboxesRequest $request): AnonymousResourceCollection
    {
        return InboxResource::collection($this->visibility->paginateForOwner($this->owner($request), $request->validated()));
    }

    public function show(Request $request, string $inbox): InboxResource
    {
        $record = $this->visibility->queryForOwner($this->owner($request))->whereKey($inbox)->firstOrFail();

        return new InboxResource($record);
    }

    public function destroy(Request $request, string $inbox): Response
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
        if (! config('inbox_lifetime.renewal_enabled', false)) {
            return ApiErrorResponse::make('renewal_disabled', 'Inbox renewal is disabled.', 403);
        }
        $owner = $this->owner($request);
        $record = Inbox::query()->ownedBy((string) $owner->getKey())->whereKey($inbox)->firstOrFail();
        try {
            $updated = $this->renewInbox->execute(
                $record,
                Carbon::parse($request->validated('expires_at')),
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

    private function commercialDenial(CommercialEntitlementDeniedException $exception): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'plan_limit_reached',
                'message' => $exception->getMessage(),
                'details' => array_filter([
                    'feature' => $exception->feature,
                    'current_value' => $exception->currentValue,
                    'allowed_limit' => $exception->allowedLimit,
                    'upgrade_required' => true,
                ], static fn (mixed $value): bool => $value !== null),
            ],
        ], 403);
    }

    private function generatedLocalPart(string $domain): string
    {
        do {
            $localPart = 'inbox-'.Str::lower(Str::random(12));
        } while (Inbox::withTrashed()->where('full_address', $localPart.'@'.strtolower($domain))->exists());

        return $localPart;
    }
}
