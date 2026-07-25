<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiErrorResponse;
use App\Jobs\ProcessOutboundProviderEventJob;
use App\Services\Outbound\OutboundProviderEventParserRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

final class OutboundWebhookController
{
    public function __construct(
        private readonly OutboundProviderEventParserRegistry $parsers,
    ) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower(trim($provider));
        $raw = $request->getContent();

        if ($provider === '' || ! isset(config('outbound.delivery_webhook.providers')[$provider])) {
            return ApiErrorResponse::make('unknown_provider', 'Unknown outbound provider.', 404);
        }

        $contentType = strtolower((string) $request->header('Content-Type', ''));
        if ($contentType !== '' && ! str_starts_with($contentType, 'application/json')) {
            return ApiErrorResponse::make('invalid_content_type', 'Unsupported content type.', 422);
        }

        $maxBytes = (int) config('outbound.delivery_webhook.max_body_bytes', 65536);
        if ($raw === '' || strlen($raw) > $maxBytes) {
            return ApiErrorResponse::make('payload_too_large', 'Payload is empty or too large.', 413);
        }

        if (! RateLimiter::attempt(
            'outbound-webhook:'.$provider.':'.$request->ip(),
            (int) config('outbound.delivery_webhook.rate_limit_per_minute', 60),
            static fn () => true,
            60,
        )) {
            Cache::increment('outbound.metrics.rate_limited');

            return ApiErrorResponse::make('rate_limit_exceeded', 'Too many webhook requests.', 429);
        }

        try {
            $parser = $this->parsers->for($provider);
        } catch (InvalidArgumentException) {
            return ApiErrorResponse::make('unknown_provider', 'Unknown outbound provider.', 404);
        }

        if (! $parser->verifySignature($request, $provider, $raw)) {
            Cache::increment('outbound.metrics.invalid_signature_attempts');

            return ApiErrorResponse::make('invalid_signature', 'Invalid webhook signature.', 401);
        }

        // Replay protection: reject identical signed payloads within the skew window.
        $timestamp = trim((string) $request->header('X-Outbound-Timestamp', ''));
        $signature = trim((string) $request->header('X-Outbound-Signature', ''));
        $replayKey = 'outbound.webhook.replay:'.hash('sha256', $provider.'|'.$timestamp.'|'.$signature);
        $skew = max(60, (int) config('outbound.delivery_webhook.timestamp_skew_seconds', 300));
        if (! Cache::add($replayKey, 1, $skew)) {
            // Duplicate delivery of the same signed request — idempotent success after first accept.
            return response()->json(['data' => ['accepted' => true, 'duplicate' => true]], 202);
        }

        try {
            $event = $parser->parse($request, $provider, $raw);
        } catch (InvalidArgumentException) {
            return ApiErrorResponse::make('invalid_payload', 'Invalid webhook payload.', 422);
        } catch (\Throwable) {
            return ApiErrorResponse::make('invalid_payload', 'Invalid webhook payload.', 422);
        }

        try {
            ProcessOutboundProviderEventJob::dispatch($event);
        } catch (\Throwable) {
            Cache::increment('outbound.metrics.event_processing_failures');

            return ApiErrorResponse::make('dispatch_unavailable', 'Outbound event processing is temporarily unavailable.', 503);
        }

        return response()->json([
            'data' => [
                'accepted' => true,
                'provider_event_id' => $event->providerEventId,
            ],
        ], 202);
    }
}
