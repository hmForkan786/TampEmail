<?php

declare(strict_types=1);

namespace App\Services\Webhook;

use App\Jobs\DeliverWebhookJob;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Fan-out domain events to subscribed webhook endpoints after commit. */
final class WebhookDispatchService
{
    public function __construct(
        private readonly WebhookDeliveryStateMachine $stateMachine,
    ) {}

    /**
     * @param  array<string, mixed>  $payloadData
     */
    public function dispatch(User $user, string $eventType, string $eventId, array $payloadData): void
    {
        if (! WebhookEventRegistry::supports($eventType)) {
            return;
        }

        $endpoints = WebhookEndpoint::query()
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => in_array($eventType, $endpoint->events ?? [], true));

        if ($endpoints->isEmpty()) {
            return;
        }

        $payload = WebhookEventRegistry::sanitizePayload($eventType, $eventId, $payloadData);
        $deliveryIds = [];

        DB::transaction(function () use ($endpoints, $payload, $eventType, $eventId, &$deliveryIds): void {
            foreach ($endpoints as $endpoint) {
                $delivery = WebhookDelivery::query()->firstOrCreate(
                    [
                        'webhook_endpoint_id' => $endpoint->getKey(),
                        'event_id' => $eventId,
                    ],
                    [
                        'event_type' => $eventType,
                        'status' => 'pending',
                        'attempt_count' => 0,
                        'payload' => $payload,
                    ],
                );

                if ($delivery->wasRecentlyCreated) {
                    $this->stateMachine->transition($delivery, 'queued');
                    $deliveryIds[] = (string) $delivery->getKey();
                }
            }
        });

        foreach ($deliveryIds as $deliveryId) {
            DeliverWebhookJob::dispatch($deliveryId)->afterCommit();
        }
    }

    public static function makeEventId(): string
    {
        return (string) Str::uuid();
    }
}
