<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\InboundWebhookDispatcher;
use App\DTOs\Inbound\ProviderWebhookEnvelope;
use App\Http\Responses\ApiErrorResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use App\Services\Inbound\InboundMetricsRecorder;

final class InboundWebhookController
{
    public function __construct(private readonly InboundWebhookDispatcher $dispatcher, private readonly InboundMetricsRecorder $metrics) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->metrics->record(null, 'received');
        $provider = trim((string) $request->header('X-Inbound-Provider', ''));
        $timestamp = trim((string) $request->header('X-Inbound-Timestamp', ''));
        $signature = trim((string) $request->header('X-Inbound-Signature', ''));
        $messageId = trim((string) $request->header('X-Inbound-Message-Id', ''));
        $raw = $request->getContent();

        if ($provider === '' || ! isset(config('inbound.providers')[$provider])) return ApiErrorResponse::make('unknown_provider', 'Unknown inbound provider.', 400);
        if ($request->header('Content-Type') !== null && ! str_starts_with(strtolower((string) $request->header('Content-Type')), 'application/json')) return ApiErrorResponse::make('invalid_content_type', 'Unsupported content type.', 422);
        if ($raw === '' || strlen($raw) > (int) config('inbound.max_body_bytes', 10485760)) return ApiErrorResponse::make('payload_too_large', 'Payload is empty or too large.', 413);
        if ($timestamp === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > (int) config('inbound.timestamp_skew_seconds', 300)) return ApiErrorResponse::make('invalid_timestamp', 'Invalid or expired webhook timestamp.', 401);
        $secret = (string) (config("inbound.providers.{$provider}.secret") ?? '');
        $expected = hash_hmac('sha256', $provider.'.'.$timestamp.'.'.$raw, $secret);
        if ($secret === '' || $signature === '' || ! hash_equals($expected, $signature)) return ApiErrorResponse::make('invalid_signature', 'Invalid webhook signature.', 401);
        if (! RateLimiter::attempt('inbound:'.$provider.':'.$request->ip(), (int) config('inbound.rate_limit_per_minute', 60), fn () => true, 60)) { $this->metrics->record(null, 'throttled'); return ApiErrorResponse::make('rate_limit_exceeded', 'Too many inbound requests.', 429); }
        if ($messageId === '') return ApiErrorResponse::make('invalid_payload', 'Provider message ID is required.', 422);
        $payload = json_decode($raw, true);
        if (! is_array($payload) || ! is_string($payload['recipient'] ?? null) || trim($payload['recipient']) === '') return ApiErrorResponse::make('invalid_payload', 'Recipient is required.', 422);
        try { $receivedAt = isset($payload['received_at']) ? Carbon::parse((string) $payload['received_at']) : now(); } catch (\Throwable) { return ApiErrorResponse::make('invalid_payload', 'Invalid received timestamp.', 422); }
        $envelope = new ProviderWebhookEnvelope($provider, $messageId, trim($payload['recipient']), isset($payload['sender']) ? (string) $payload['sender'] : null, $receivedAt, isset($payload['raw_mime_payload']) ? (string) $payload['raw_mime_payload'] : $raw, strlen($raw));
        try { $this->dispatcher->dispatch($envelope); $this->metrics->record(null, 'queued'); } catch (\Throwable) { $this->metrics->record(null, 'rejected'); return ApiErrorResponse::make('dispatch_unavailable', 'Inbound processing is temporarily unavailable.', 503); }
        return response()->json(['data' => ['accepted' => true, 'provider_message_id' => $messageId]], 202);
    }
}
