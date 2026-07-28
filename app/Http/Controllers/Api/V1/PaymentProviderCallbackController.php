<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Billing\WebhookRequestData;
use App\Http\Controllers\Controller;
use App\Services\Billing\PaymentCallbackIngestionService;
use App\Services\Billing\PaymentPayloadRedactor;
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
    ): JsonResponse {
        $raw = $request->getContent();
        if (strlen($raw) > (int) config('billing.callbacks.max_payload_bytes', 262144)) {
            return response()->json(['accepted' => false, 'code' => 'payload_too_large'], 413);
        }
        if (! str_contains(strtolower((string) $request->header('Content-Type')), 'application/json')) {
            return response()->json(['accepted' => false, 'code' => 'unsupported_content_type'], 415);
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
