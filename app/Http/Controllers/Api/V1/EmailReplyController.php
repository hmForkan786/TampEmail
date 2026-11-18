<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outbound\CreateOutboundReplyAction;
use App\DTOs\Outbound\CreateOutboundReplyData;
use App\Exceptions\OutboundSendException;
use App\Http\Requests\Outbound\StoreOutboundReplyRequest;
use App\Http\Resources\OutboundMessageResource;
use App\Http\Responses\ApiErrorResponse;
use App\Models\User;
use App\Services\Commercial\CommercialResponseFactory;
use Illuminate\Http\JsonResponse;

final class EmailReplyController
{
    public function __construct(
        private readonly CreateOutboundReplyAction $createOutboundReply,
        private readonly CommercialResponseFactory $commercialResponses,
    ) {}

    public function store(StoreOutboundReplyRequest $request, string $email): JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');
        $apiKey = $request->attributes->get('apiKey');

        try {
            $message = $this->createOutboundReply->execute(
                new CreateOutboundReplyData(
                    emailId: $email,
                    idempotencyKey: $request->string('idempotency_key')->toString(),
                    textBody: $request->input('text_body'),
                    htmlBody: $request->input('html_body'),
                    subject: $request->input('subject'),
                    cc: $request->input('cc', []),
                ),
                $owner,
                $apiKey !== null ? (string) $apiKey->getKey() : null,
            );
        } catch (OutboundSendException $exception) {
            return $this->mapCommercialException($exception, $owner);
        }

        return (new OutboundMessageResource($message))->response()->setStatusCode(201);
    }

    private function mapCommercialException(OutboundSendException $exception, User $owner): JsonResponse
    {
        if (in_array($exception->errorCode, ['plan_limit_reached', 'feature_not_available'], true)) {
            return $this->commercialResponses->fromOutboundSendException($exception, $owner);
        }

        return ApiErrorResponse::make($exception->errorCode, $exception->getMessage(), $exception->status);
    }
}
