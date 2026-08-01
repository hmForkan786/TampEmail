<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outbound\CancelOutboundMessageAction;
use App\Actions\Outbound\CreateOutboundSendAction;
use App\Actions\Outbound\DeleteOutboundMessageAction;
use App\Actions\Outbound\RescheduleOutboundMessageAction;
use App\Actions\Outbound\RetryOutboundMessageAction;
use App\Actions\Outbound\SendScheduledMessageNowAction;
use App\Actions\Outbound\UnscheduleOutboundMessageAction;
use App\DTOs\Outbound\CreateOutboundSendData;
use App\Exceptions\OutboundSendException;
use App\Http\Requests\Outbound\RescheduleOutboundMessageRequest;
use App\Http\Requests\Outbound\SendScheduledMessageNowRequest;
use App\Http\Requests\Outbound\StoreOutboundMessageRequest;
use App\Http\Requests\Outbound\UnscheduleOutboundMessageRequest;
use App\Http\Resources\OutboundMessageCollection;
use App\Http\Resources\OutboundMessageResource;
use App\Http\Responses\ApiErrorResponse;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Commercial\CommercialResponseFactory;
use App\Services\Outbound\OutboundMessageAccessService;
use App\Services\Outbound\OutboundMessageListingService;
use App\Services\Outbound\OutboundMessageTimelineBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OutboundMessageController
{
    public function __construct(
        private readonly CreateOutboundSendAction $createOutboundSend,
        private readonly CancelOutboundMessageAction $cancelOutboundMessage,
        private readonly DeleteOutboundMessageAction $deleteOutboundMessage,
        private readonly RetryOutboundMessageAction $retryOutboundMessage,
        private readonly RescheduleOutboundMessageAction $rescheduleOutboundMessage,
        private readonly UnscheduleOutboundMessageAction $unscheduleOutboundMessage,
        private readonly SendScheduledMessageNowAction $sendScheduledMessageNow,
        private readonly OutboundMessageTimelineBuilder $timelineBuilder,
        private readonly OutboundMessageListingService $listingService,
        private readonly OutboundMessageAccessService $accessService,
        private readonly CommercialResponseFactory $commercialResponses,
    ) {}

    public function index(Request $request): OutboundMessageCollection
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');

        return new OutboundMessageCollection($this->listingService->list($owner, $request->query()));
    }

    public function store(StoreOutboundMessageRequest $request): OutboundMessageResource|JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');
        $apiKey = $request->attributes->get('apiKey');

        try {
            $message = $this->createOutboundSend->execute(
                new CreateOutboundSendData(
                    inboxId: $request->string('inbox_id')->toString(),
                    idempotencyKey: $request->string('idempotency_key')->toString(),
                    to: $request->input('to', []),
                    cc: $request->input('cc', []),
                    bcc: $request->input('bcc', []),
                    subject: $request->string('subject')->toString(),
                    textBody: $request->input('text_body'),
                    htmlBody: $request->input('html_body'),
                    fromDisplayName: $request->input('from_display_name'),
                    senderProfileId: $request->input('sender_profile_id'),
                ),
                $owner,
                $apiKey !== null ? (string) $apiKey->getKey() : null,
            );
        } catch (OutboundSendException $exception) {
            return $this->mapOutboundException($exception, $owner);
        }

        return (new OutboundMessageResource($message))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $message): OutboundMessageResource|JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');

        $outbound = $this->accessService->findOwned($owner, $message);

        if ($outbound === null) {
            return ApiErrorResponse::make('not_found', 'Outbound message not found.', 404);
        }

        $outbound->setRelation('safeAttachments', $this->accessService->listSafeAttachments($outbound));

        return new OutboundMessageResource($outbound);
    }

    /**
     * Safe, redacted delivery timeline for the message owner. Never
     * exposes raw provider payloads, secrets, BCC, message bodies, bounce
     * diagnostics, complaint metadata, or provider signature details.
     */
    public function timeline(Request $request, string $message): JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');

        $outbound = $this->accessService->findOwned($owner, $message);

        if ($outbound === null) {
            return ApiErrorResponse::make('not_found', 'Outbound message not found.', 404);
        }

        return response()->json([
            'data' => [
                'id' => (string) $outbound->getKey(),
                'state' => $outbound->state?->value,
                'timeline' => $this->timelineBuilder->build($outbound, admin: false),
            ],
        ]);
    }

    public function schedule(RescheduleOutboundMessageRequest $request, string $message): OutboundMessageResource|JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');
        $apiKey = $request->attributes->get('apiKey');

        if ($this->accessService->findOwned($owner, $message) === null) {
            return ApiErrorResponse::make('not_found', 'Outbound message not found.', 404);
        }

        try {
            $outbound = $this->rescheduleOutboundMessage->execute(
                $owner,
                $message,
                $request->integer('schedule_version'),
                $request->string('local_date')->toString(),
                $request->string('local_time')->toString(),
                $request->string('timezone')->toString(),
                $apiKey !== null ? (string) $apiKey->getKey() : null,
            );
        } catch (OutboundSendException $exception) {
            $details = $exception->errorCode === 'schedule_conflict'
                ? ['schedule_version' => OutboundMessage::query()->whereKey($message)->where('user_id', $owner->getKey())->value('schedule_version')]
                : [];

            return $this->mapOutboundException($exception, $owner, $details);
        }

        return new OutboundMessageResource($outbound);
    }

    public function unschedule(UnscheduleOutboundMessageRequest $request, string $message): OutboundMessageResource|JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');

        if ($this->accessService->findOwned($owner, $message) === null) {
            return ApiErrorResponse::make('not_found', 'Outbound message not found.', 404);
        }

        try {
            $outbound = $this->unscheduleOutboundMessage->execute(
                $owner,
                $message,
                $request->integer('schedule_version'),
            );
        } catch (OutboundSendException $exception) {
            $details = $exception->errorCode === 'schedule_conflict'
                ? ['schedule_version' => OutboundMessage::query()->whereKey($message)->where('user_id', $owner->getKey())->value('schedule_version')]
                : [];

            return $this->mapOutboundException($exception, $owner, $details);
        }

        return new OutboundMessageResource($outbound);
    }

    public function sendNow(SendScheduledMessageNowRequest $request, string $message): OutboundMessageResource|JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');
        $apiKey = $request->attributes->get('apiKey');

        if ($this->accessService->findOwned($owner, $message) === null) {
            return ApiErrorResponse::make('not_found', 'Outbound message not found.', 404);
        }

        try {
            $outbound = $this->sendScheduledMessageNow->execute(
                $owner,
                $message,
                $request->integer('schedule_version'),
                $apiKey !== null ? (string) $apiKey->getKey() : null,
            );
        } catch (OutboundSendException $exception) {
            $details = $exception->errorCode === 'schedule_conflict'
                ? ['schedule_version' => OutboundMessage::query()->whereKey($message)->where('user_id', $owner->getKey())->value('schedule_version')]
                : [];

            return $this->mapOutboundException($exception, $owner, $details);
        }

        return new OutboundMessageResource($outbound);
    }

    public function cancel(Request $request, string $message): OutboundMessageResource|JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');

        try {
            $outbound = $this->cancelOutboundMessage->execute($message, $owner);
        } catch (OutboundSendException $exception) {
            return $this->mapOutboundException($exception, $owner);
        }

        return new OutboundMessageResource($outbound);
    }

    public function retry(Request $request, string $message): OutboundMessageResource|JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');
        $apiKey = $request->attributes->get('apiKey');

        try {
            $outbound = $this->retryOutboundMessage->execute(
                $message,
                $owner,
                $apiKey !== null ? (string) $apiKey->getKey() : null,
            );
        } catch (OutboundSendException $exception) {
            return $this->mapOutboundException($exception, $owner);
        }

        return new OutboundMessageResource($outbound);
    }

    /**
     * Hides the message from the owner's normal views. Never rewrites
     * transport state; a still-queued message is cancelled first (same
     * rule the `cancel` endpoint uses). Hard deletion happens much later,
     * only via the retention prune command.
     */
    public function destroy(Request $request, string $message): JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');

        try {
            $this->deleteOutboundMessage->execute($message, $owner);
        } catch (OutboundSendException $exception) {
            return $this->mapOutboundException($exception, $owner);
        }

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** @param  array<string, mixed>  $details */
    private function mapOutboundException(OutboundSendException $exception, User $owner, array $details = []): JsonResponse
    {
        if (in_array($exception->errorCode, ['plan_limit_reached', 'feature_not_available'], true)) {
            return $this->commercialResponses->fromOutboundSendException($exception, $owner);
        }

        return ApiErrorResponse::make($exception->errorCode, $exception->getMessage(), $exception->status, $details);
    }
}
