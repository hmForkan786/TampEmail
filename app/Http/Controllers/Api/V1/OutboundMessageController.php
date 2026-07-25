<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outbound\CreateOutboundSendAction;
use App\DTOs\Outbound\CreateOutboundSendData;
use App\Exceptions\OutboundSendException;
use App\Http\Requests\Outbound\StoreOutboundMessageRequest;
use App\Http\Resources\OutboundMessageResource;
use App\Http\Responses\ApiErrorResponse;
use App\Models\OutboundMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class OutboundMessageController
{
    public function __construct(
        private readonly CreateOutboundSendAction $createOutboundSend,
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
}
