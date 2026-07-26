<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outbound\ScheduleOutboundDraftAction;
use App\Exceptions\OutboundSendException;
use App\Http\Requests\Outbound\ScheduleOutboundDraftRequest;
use App\Http\Requests\Outbound\StoreOutboundDraftRequest;
use App\Http\Requests\Outbound\UpdateOutboundDraftRequest;
use App\Http\Resources\OutboundMessageResource;
use App\Http\Responses\ApiErrorResponse;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Outbound\OutboundDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OutboundDraftController
{
    public function __construct(
        private readonly OutboundDraftService $drafts,
        private readonly ScheduleOutboundDraftAction $scheduleOutboundDraft,
    ) {}

    public function index(Request $request): mixed
    { /** @var User $owner */ $owner = $request->attributes->get('apiKeyOwner');

        return OutboundMessageResource::collection(OutboundMessage::query()->where('user_id', $owner->getKey())->where('state', 'draft')->whereNull('draft_deleted_at')->select(['id', 'user_id', 'inbox_id', 'operation', 'state', 'subject', 'to_recipients', 'attachment_ids', 'draft_version', 'created_at', 'updated_at'])->latest()->paginate());
    }

    public function show(Request $request, string $draft): OutboundMessageResource|JsonResponse
    {
        $model = $this->find($request, $draft);

        return $model ? new OutboundMessageResource($model) : ApiErrorResponse::make('not_found', 'Draft not found.', 404);
    }

    public function store(StoreOutboundDraftRequest $request): JsonResponse
    { /** @var User $owner */ $owner = $request->attributes->get('apiKeyOwner');
        try {
            $draft = $this->drafts->create($owner, $request->validated());
        } catch (OutboundSendException $e) {
            return ApiErrorResponse::make($e->errorCode, $e->getMessage(), $e->status);
        }

        return (new OutboundMessageResource($draft))->response()->setStatusCode(201);
    }

    public function update(UpdateOutboundDraftRequest $request, string $draft): OutboundMessageResource|JsonResponse
    { /** @var User $owner */ $owner = $request->attributes->get('apiKeyOwner');
        try {
            return new OutboundMessageResource($this->drafts->update($owner, $draft, $request->validated()));
        } catch (OutboundSendException $e) {
            return ApiErrorResponse::make($e->errorCode, $e->getMessage(), $e->status, $e->errorCode === 'draft_conflict' ? ['version' => OutboundMessage::query()->whereKey($draft)->where('user_id', $owner->getKey())->value('draft_version')] : null);
        }
    }

    public function destroy(Request $request, string $draft): JsonResponse
    { /** @var User $owner */ $owner = $request->attributes->get('apiKeyOwner');
        try {
            $this->drafts->delete($owner, $draft, $request->integer('version') ?: null);
        } catch (OutboundSendException $e) {
            return ApiErrorResponse::make($e->errorCode, $e->getMessage(), $e->status);
        }

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function schedule(ScheduleOutboundDraftRequest $request, string $draft): OutboundMessageResource|JsonResponse
    { /** @var User $owner */ $owner = $request->attributes->get('apiKeyOwner');
        $apiKey = $request->attributes->get('apiKey');
        try {
            return new OutboundMessageResource($this->scheduleOutboundDraft->execute(
                $owner,
                $draft,
                $request->integer('version'),
                $request->string('local_date')->toString(),
                $request->string('local_time')->toString(),
                $request->string('timezone')->toString(),
                $apiKey ? (string) $apiKey->getKey() : null,
            ));
        } catch (OutboundSendException $e) {
            $details = $e->errorCode === 'draft_conflict'
                ? ['version' => OutboundMessage::query()->whereKey($draft)->where('user_id', $owner->getKey())->value('draft_version')]
                : [];

            return ApiErrorResponse::make($e->errorCode, $e->getMessage(), $e->status, $details);
        }
    }

    public function submit(Request $request, string $draft): OutboundMessageResource|JsonResponse
    { /** @var User $owner */ $owner = $request->attributes->get('apiKeyOwner');
        $apiKey = $request->attributes->get('apiKey');
        $version = $request->integer('version');
        if ($version < 1) {
            return ApiErrorResponse::make('validation_failed', 'The given data was invalid.', 422, ['version' => ['The version field is required.']]);
        } try {
            return new OutboundMessageResource($this->drafts->submit($owner, $draft, $version, $apiKey ? (string) $apiKey->getKey() : null));
        } catch (OutboundSendException $e) {
            return ApiErrorResponse::make($e->errorCode, $e->getMessage(), $e->status);
        }
    }

    private function find(Request $request, string $id): ?OutboundMessage
    {
        $owner = $request->attributes->get('apiKeyOwner');

        return OutboundMessage::query()->whereKey($id)->where('user_id', $owner->getKey())->where('state', 'draft')->whereNull('draft_deleted_at')->first();
    }
}
