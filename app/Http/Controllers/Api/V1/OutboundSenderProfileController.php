<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\OutboundSendException;
use App\Http\Requests\Outbound\StoreOutboundSenderProfileRequest;
use App\Http\Requests\Outbound\UpdateOutboundSenderProfileRequest;
use App\Http\Resources\OutboundSenderProfileResource;
use App\Http\Responses\ApiErrorResponse;
use App\Models\User;
use App\Services\Outbound\OutboundSenderProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OutboundSenderProfileController
{
    public function __construct(
        private readonly OutboundSenderProfileService $profiles,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');
        $inboxId = $request->query('inbox_id');

        return response()->json([
            'data' => OutboundSenderProfileResource::collection(
                $this->profiles->list($owner, is_string($inboxId) ? $inboxId : null),
            ),
        ]);
    }

    public function show(Request $request, string $profile): OutboundSenderProfileResource|JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');

        try {
            return new OutboundSenderProfileResource($this->profiles->findOwned($owner, $profile));
        } catch (OutboundSendException $e) {
            return ApiErrorResponse::make($e->errorCode, $e->getMessage(), $e->status);
        }
    }

    public function store(StoreOutboundSenderProfileRequest $request): JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');

        try {
            $created = $this->profiles->create($owner, $request->validated());
        } catch (OutboundSendException $e) {
            return ApiErrorResponse::make($e->errorCode, $e->getMessage(), $e->status);
        }

        return (new OutboundSenderProfileResource($created))->response()->setStatusCode(201);
    }

    public function update(UpdateOutboundSenderProfileRequest $request, string $profile): OutboundSenderProfileResource|JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');

        try {
            $updated = $this->profiles->update($owner, $profile, $request->validated(), $request->integer('version'));
        } catch (OutboundSendException $e) {
            $details = $e->errorCode === 'profile_conflict'
                ? ['version' => $request->integer('version')]
                : null;

            return ApiErrorResponse::make($e->errorCode, $e->getMessage(), $e->status, $details);
        }

        return new OutboundSenderProfileResource($updated);
    }

    public function destroy(Request $request, string $profile): JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');
        $version = $request->integer('version') ?: null;

        try {
            $this->profiles->delete($owner, $profile, $version);
        } catch (OutboundSendException $e) {
            return ApiErrorResponse::make($e->errorCode, $e->getMessage(), $e->status);
        }

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function makeDefault(Request $request, string $profile): OutboundSenderProfileResource|JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');
        $version = $request->integer('version');
        if ($version < 1) {
            return ApiErrorResponse::make('validation_failed', 'The given data was invalid.', 422, ['version' => ['The version field is required.']]);
        }

        try {
            return new OutboundSenderProfileResource($this->profiles->makeDefault($owner, $profile, $version));
        } catch (OutboundSendException $e) {
            return ApiErrorResponse::make($e->errorCode, $e->getMessage(), $e->status);
        }
    }
}
