<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Audit\AuditLogWriter;
use App\Services\Entitlement\EntitlementService;
use App\Services\Webhook\WebhookDeliveryStateMachine;
use App\Services\Webhook\WebhookEndpointService;
use App\Services\Webhook\WebhookSecurityValidator;
use App\Services\Webhook\WebhookSignatureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $deliveryId)
    {
        $this->onQueue((string) config('queue.workloads.webhooks', 'webhooks'));
    }

    public function handle(
        EntitlementService $entitlements,
        WebhookEndpointService $endpoints,
        WebhookSecurityValidator $security,
        WebhookSignatureService $signatures,
        WebhookDeliveryStateMachine $stateMachine,
        AuditLogWriter $audit,
    ): void {
        $delivery = WebhookDelivery::query()->with(['endpoint' => fn ($query) => $query->withTrashed()->with('user')])->find($this->deliveryId);
        if ($delivery === null) {
            return;
        }

        if (! in_array($delivery->status, ['queued', 'retry_scheduled'], true)) {
            return;
        }

        $endpoint = $delivery->endpoint;
        if (! $endpoint instanceof WebhookEndpoint || $endpoint->trashed() || ! $endpoint->is_active) {
            $this->cancel($delivery, $stateMachine, $audit, $endpoint?->user_id, 'endpoint_inactive');

            return;
        }

        $user = $endpoint->user;
        if ($user === null || ! $entitlements->allows($user, 'webhook.access')) {
            $this->cancel($delivery, $stateMachine, $audit, $endpoint->user_id, 'entitlement_revoked');

            return;
        }

        $maxAttempts = max(1, (int) config('webhooks.max_delivery_attempts', 5));
        if ($delivery->attempt_count >= $maxAttempts) {
            $stateMachine->transition($delivery, 'failed', ['failure_code' => 'max_attempts_exceeded']);

            return;
        }

        try {
            $security->assertSafeUrl($endpoint->url);
        } catch (Throwable) {
            $this->cancel($delivery, $stateMachine, $audit, $endpoint->user_id, 'url_unsafe');

            return;
        }

        if ($delivery->status === 'retry_scheduled') {
            $stateMachine->transition($delivery, 'queued');
            $delivery->refresh();
        }

        $stateMachine->transition($delivery, 'delivering');
        $delivery->refresh();

        $rawBody = json_encode($delivery->payload, JSON_THROW_ON_ERROR);
        $timestamp = now()->getTimestamp();
        $headers = $signatures->headers($delivery, $endpoint->secret_encrypted, $rawBody, $timestamp);

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => (float) config('webhooks.connect_timeout_seconds', 5),
                'connect_timeout' => (float) config('webhooks.connect_timeout_seconds', 5),
            ])
                ->withHeaders($headers)
                ->withBody($rawBody, 'application/json')
                ->post($endpoint->url);

            $delivery->increment('attempt_count');
            $delivery->refresh();

            if ($response->successful()) {
                $stateMachine->transition($delivery, 'delivered', [
                    'response_status' => $response->status(),
                    'delivered_at' => now(),
                    'failure_code' => null,
                    'response_excerpt' => null,
                ]);
                $endpoint->forceFill(['last_delivery_at' => now()])->save();
                $audit->write('commercial.webhook_delivery_succeeded', (string) $endpoint->user_id, $delivery, null, null, [
                    'response_status' => $response->status(),
                    'attempt' => $delivery->attempt_count,
                ]);

                return;
            }

            $this->handleFailure(
                $delivery,
                $stateMachine,
                $audit,
                $endpoint,
                $response->status(),
                Str::limit($response->body(), (int) config('webhooks.response_excerpt_limit', 512)),
                $maxAttempts,
            );
        } catch (ConnectionException $exception) {
            $delivery->increment('attempt_count');
            $this->handleFailure(
                $delivery,
                $stateMachine,
                $audit,
                $endpoint,
                null,
                Str::limit($exception->getMessage(), (int) config('webhooks.response_excerpt_limit', 512)),
                $maxAttempts,
                'connection_timeout',
            );
        } catch (Throwable $exception) {
            $delivery->increment('attempt_count');
            $stateMachine->transition($delivery, 'failed', [
                'failure_code' => 'delivery_exception',
                'response_excerpt' => Str::limit($exception->getMessage(), (int) config('webhooks.response_excerpt_limit', 512)),
            ]);
            $audit->write('commercial.webhook_delivery_failed', (string) $endpoint->user_id, $delivery, null, null, [
                'failure_code' => 'delivery_exception',
            ]);
        }
    }

    private function handleFailure(
        WebhookDelivery $delivery,
        WebhookDeliveryStateMachine $stateMachine,
        AuditLogWriter $audit,
        WebhookEndpoint $endpoint,
        ?int $status,
        string $excerpt,
        int $maxAttempts,
        ?string $failureCode = null,
    ): void {
        $failureCode ??= $status !== null ? 'http_'.$status : 'network_error';
        $retryable = $this->isRetryable($status, $failureCode);
        $attemptsRemaining = $maxAttempts - $delivery->attempt_count;

        if ($retryable && $attemptsRemaining > 0) {
            $delay = $this->backoffSeconds($delivery->attempt_count);
            $stateMachine->transition($delivery, 'retry_scheduled', [
                'response_status' => $status,
                'response_excerpt' => $excerpt,
                'failure_code' => $failureCode,
                'next_attempt_at' => now()->addSeconds($delay),
            ]);
            self::dispatch((string) $delivery->getKey())->delay($delay);

            return;
        }

        $stateMachine->transition($delivery, 'failed', [
            'response_status' => $status,
            'response_excerpt' => $excerpt,
            'failure_code' => $failureCode,
        ]);
        $audit->write('commercial.webhook_delivery_failed', (string) $endpoint->user_id, $delivery, null, null, [
            'response_status' => $status,
            'failure_code' => $failureCode,
            'attempt' => $delivery->attempt_count,
        ]);
    }

    private function isRetryable(?int $status, string $failureCode): bool
    {
        if ($failureCode === 'connection_timeout' || $failureCode === 'network_error') {
            return true;
        }

        if ($status === null) {
            return true;
        }

        return in_array($status, [408, 425, 429], true) || $status >= 500;
    }

    private function backoffSeconds(int $attemptCount): int
    {
        $base = [30, 120, 600];
        $index = max(0, min(count($base) - 1, $attemptCount - 1));
        $jitter = random_int(0, 5);

        return $base[$index] + $jitter;
    }

    private function cancel(
        WebhookDelivery $delivery,
        WebhookDeliveryStateMachine $stateMachine,
        AuditLogWriter $audit,
        ?string $userId,
        string $reason,
    ): void {
        if (! $stateMachine->canTransition($delivery->status, 'cancelled')) {
            return;
        }

        $stateMachine->transition($delivery, 'cancelled', ['failure_code' => $reason]);
        if ($userId !== null) {
            $audit->write('commercial.webhook_delivery_cancelled', $userId, $delivery, null, null, ['reason' => $reason]);
        }
    }
}
