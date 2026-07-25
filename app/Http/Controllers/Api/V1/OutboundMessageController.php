<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outbound\CancelOutboundMessageAction;
use App\Actions\Outbound\CreateOutboundSendAction;
use App\Actions\Outbound\RetryOutboundMessageAction;
use App\DTOs\Outbound\CreateOutboundSendData;
use App\Exceptions\OutboundSendException;
use App\Http\Requests\Outbound\StoreOutboundMessageRequest;
use App\Http\Resources\OutboundMessageResource;
use App\Http\Responses\ApiErrorResponse;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Outbound\OutboundMessageTimelineBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OutboundMessageController
{
    public function __construct(
        private readonly CreateOutboundSendAction $createOutboundSend,
        private readonly CancelOutboundMessageAction $cancelOutboundMessage,
        private readonly RetryOutboundMessageAction $retryOutboundMessage,
        private readonly OutboundMessageTimelineBuilder $timelineBuilder,
    ) {}

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
                ),
                $owner,
                $apiKey !== null ? (string) $apiKey->getKey() : null,
            );
        } catch (OutboundSendException $exception) {
            return ApiErrorResponse::make($exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return (new OutboundMessageResource($message))->response()->setStatusCode(201);
    }

    public function show(string $message): OutboundMessageResource|JsonResponse
    {
        /** @var User $owner */
        $owner = request()->attributes->get('apiKeyOwner');

        $outbound = OutboundMessage::query()
            ->whereKey($message)
            ->where('user_id', $owner->getKey())
            ->first();

        if ($outbound === null) {
            return ApiErrorResponse::make('not_found', 'Outbound message not found.', 404);
        }

        return new OutboundMessageResource($outbound);
    }

    /**
     * Safe, redacted delivery timeline for the message owner. Never
     * exposes raw provider payloads, secrets, BCC, message bodies, bounce
     * diagnostics, complaint metadata, or provider signature details.
     */
    public function timeline(string $message): JsonResponse
    {
        /** @var User $owner */
        $owner = request()->attributes->get('apiKeyOwner');

        $outbound = OutboundMessage::query()
            ->whereKey($message)
            ->where('user_id', $owner->getKey())
            ->first();

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

    public function cancel(Request $request, string $message): OutboundMessageResource|JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');

        try {
            $outbound = $this->cancelOutboundMessage->execute($message, $owner);
        } catch (OutboundSendException $exception) {
            return ApiErrorResponse::make($exception->errorCode, $exception->getMessage(), $exception->status);
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
            return ApiErrorResponse::make($exception->errorCode, $exception->getMessage(), $exception->status);
        }

        return new OutboundMessageResource($outbound);
    }
}
