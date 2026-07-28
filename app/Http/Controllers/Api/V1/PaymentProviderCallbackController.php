<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Billing\RawWebhookRequest;
use App\DTOs\Billing\WebhookRequestData;
use App\Http\Controllers\Controller;
use App\Services\Billing\PaymentCallbackIngestionService;
use App\Services\Billing\PaymentPayloadRedactor;
use App\Services\Billing\Webhook\ProviderWebhookVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class PaymentProviderCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        PaymentCallbackIngestionService $ingestion,
        PaymentPayloadRedactor $redactor,
        ProviderWebhookVerificationService $verification,
    ): JsonResponse {
        $rawRequest = RawWebhookRequest::capture($request, $provider);
        $raw = $rawRequest->rawBody;
        if (strlen($raw) > (int) config('billing.webhook_security.max_payload_bytes', 262144)) {
            return response()->json(['accepted' => false, 'code' => 'payload_too_large'], 413);
        }
        if (! in_array($rawRequest->contentType, (array) config('billing.webhook_security.allowed_content_types', ['application/json']), true)) {
            return response()->json(['accepted' => false, 'code' => 'unsupported_content_type'], 415);
        }
        $allowedNetworks = (array) config("billing.webhook_security.providers.{$rawRequest->provider}.allowed_source_ips", []);
        if ($allowedNetworks !== [] && ! in_array($rawRequest->sourceIp, $allowedNetworks, true)) {
            return response()->json(['accepted' => false, 'code' => 'invalid_webhook_request'], 400);
        }
        $verified = $verification->verify($rawRequest);
        if (! $verified->verified) {
            $status = in_array($verified->failureCode, ['provider_unknown'], true) ? 404
                : ($verified->failureCode === 'verification_adapter_not_configured' ? 503 : 401);

            return response()->json(['accepted' => false, 'code' => 'invalid_webhook_signature'], $status);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['accepted' => false, 'code' => 'malformed_payload'], 422);
        }

        try {
            $result = $ingestion->ingest(new WebhookRequestData(
                provider: $provider,
                headers: $redactor->redact($request->headers->all()),
                payload: $payload,
                rawBody: $raw,
            ));

            return response()->json([
                'accepted' => $result->accepted,
                'duplicate' => $result->duplicate,
                'event_id' => $result->internalEventId,
                'status' => $result->processingStatus,
            ], 202);
        } catch (Throwable) {
            return response()->json(['accepted' => false, 'code' => 'callback_rejected'], 400);
        }
    }
}
