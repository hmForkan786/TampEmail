<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outbound\CreateOutboundForwardAction;
use App\DTOs\Outbound\CreateOutboundForwardData;
use App\Exceptions\OutboundSendException;
use App\Http\Requests\Outbound\StoreOutboundForwardRequest;
use App\Http\Resources\OutboundMessageResource;
use App\Http\Responses\ApiErrorResponse;
use App\Models\User;
use App\Services\Commercial\CommercialResponseFactory;
use Illuminate\Http\JsonResponse;

final class EmailForwardController
{
    public function __construct(
        private readonly CreateOutboundForwardAction $createOutboundForward,
        private readonly CommercialResponseFactory $commercialResponses,
    ) {}

    public function store(StoreOutboundForwardRequest $request, string $email): JsonResponse
    {
        /** @var User $owner */
        $owner = $request->attributes->get('apiKeyOwner');
        $apiKey = $request->attributes->get('apiKey');

        try {
            $message = $this->createOutboundForward->execute(
                new CreateOutboundForwardData(
                    emailId: $email,
                    idempotencyKey: $request->string('idempotency_key')->toString(),
                    to: $request->input('to', []),
                    cc: $request->input('cc', []),
                    bcc: $request->input('bcc', []),
                    textBody: $request->input('text_body'),
                    htmlBody: $request->input('html_body'),
                    subject: $request->input('subject'),
                    attachmentIds: $request->input('attachment_ids', []),
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
