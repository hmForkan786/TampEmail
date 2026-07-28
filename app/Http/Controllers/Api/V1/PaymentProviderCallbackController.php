<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Billing\RawWebhookRequest;
use App\DTOs\Billing\WebhookRequestData;
use App\Http\Controllers\Controller;
use App\Services\Billing\Callback\ProviderCallbackResponseFormatterRegistry;
use App\Services\Billing\Payload\ProviderPayloadParserRegistry;
use App\Services\Billing\PaymentCallbackIngestionService;
use App\Services\Billing\PaymentPayloadRedactor;
use App\Services\Billing\Webhook\ProviderWebhookVerificationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class PaymentProviderCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        PaymentCallbackIngestionService $ingestion,
        PaymentPayloadRedactor $redactor,
        ProviderWebhookVerificationService $verification,
        ProviderPayloadParserRegistry $parsers,
        ProviderCallbackResponseFormatterRegistry $formatters,
    ): Response {
        $rawRequest = RawWebhookRequest::capture($request, $provider);
        $formatter = $formatters->resolve($rawRequest->provider);
        $raw = $rawRequest->rawBody;
        if (strlen($raw) > (int) config('billing.webhook_security.max_payload_bytes', 262144)) {
            return $formatter->rejected(413);
        }
        if (! in_array($rawRequest->contentType, (array) config('billing.webhook_security.allowed_content_types', ['application/json']), true)) {
            return $formatter->rejected(415);
        }
        $allowedNetworks = (array) config("billing.webhook_security.providers.{$rawRequest->provider}.allowed_source_ips", []);
        if ($allowedNetworks !== [] && ! in_array($rawRequest->sourceIp, $allowedNetworks, true)) {
            return $formatter->rejected(400);
        }
        $verified = $verification->verify($rawRequest);
        if (! $verified->verified) {
            $status = in_array($verified->failureCode, ['provider_unknown'], true) ? 404
                : ($verified->failureCode === 'verification_adapter_not_configured' ? 503 : 401);

            return $formatter->rejected($status);
        }
        $parser = $parsers->resolve($rawRequest->provider, $rawRequest->contentType);
        if ($parser === null) {
            return $formatter->rejected(415);
        }
        try {
            $payload = $parser->parse($raw, $rawRequest->contentType);
        } catch (Throwable) {
            return $formatter->rejected(422);
        }

        try {
            $result = $ingestion->ingest(new WebhookRequestData(
                provider: $provider,
                headers: $redactor->redact($request->headers->all()),
                payload: $payload,
                rawBody: $raw,
            ));

            return $formatter->accepted($result);
        } catch (Throwable) {
            return $formatter->rejected(400);
        }
    }
}
