<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\OutboundSendException;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Commercial\CommercialApiErrorMapper;
use App\Services\Entitlement\EntitlementService;
use App\Services\Webhook\WebhookEndpointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebhookEndpointController
{
    public function __construct(
        private readonly WebhookEndpointService $webhooks,
        private readonly EntitlementService $entitlements,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->owner($request);

        return response()->json([
            'data' => WebhookEndpoint::query()
                ->with('user')
                ->where('user_id', $user->id)
                ->get()
                ->map(fn (WebhookEndpoint $endpoint): array => $this->serialize($endpoint)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->owner($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'string', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $this->webhooks->create($user, $data);
        } catch (OutboundSendException $exception) {
            return $this->mapException($exception, $user);
        }

        return response()->json([
            'data' => $this->serialize($result['endpoint']),
            'secret' => $result['secret'],
        ], 201);
    }

    public function show(Request $request, string $webhook): JsonResponse
    {
        return response()->json(['data' => $this->serialize($this->owned($request, $webhook))]);
    }

    public function update(Request $request, string $webhook): JsonResponse
    {
        $user = $this->owner($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'url' => ['sometimes', 'string', 'max:2048'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', 'max:100'],
        ]);

        try {
            $endpoint = $this->webhooks->update($this->owned($request, $webhook), $user, $data);
        } catch (OutboundSendException $exception) {
            return $this->mapException($exception, $user);
        }

        return response()->json(['data' => $this->serialize($endpoint)]);
    }

    public function destroy(Request $request, string $webhook): JsonResponse
    {
        $user = $this->owner($request);

        try {
            $this->webhooks->delete($this->owned($request, $webhook), $user);
        } catch (OutboundSendException $exception) {
            return $this->mapException($exception, $user);
        }

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function enable(Request $request, string $webhook): JsonResponse
    {
        $user = $this->owner($request);

        try {
            $endpoint = $this->webhooks->enable($this->owned($request, $webhook), $user);
        } catch (OutboundSendException $exception) {
            return $this->mapException($exception, $user);
        }

        return response()->json(['data' => $this->serialize($endpoint)]);
    }

    public function disable(Request $request, string $webhook): JsonResponse
    {
        $user = $this->owner($request);

        try {
            $endpoint = $this->webhooks->disable($this->owned($request, $webhook), $user);
        } catch (OutboundSendException $exception) {
            return $this->mapException($exception, $user);
        }

        return response()->json(['data' => $this->serialize($endpoint)]);
    }

    public function rotateSecret(Request $request, string $webhook): JsonResponse
    {
        $user = $this->owner($request);

        try {
            $result = $this->webhooks->rotateSecret($this->owned($request, $webhook), $user);
        } catch (OutboundSendException $exception) {
            return $this->mapException($exception, $user);
        }

        return response()->json([
            'data' => $this->serialize($result['endpoint']),
            'secret' => $result['secret'],
        ]);
    }

    public function deliveries(Request $request, string $webhook): JsonResponse
    {
        $endpoint = $this->owned($request, $webhook);

        return response()->json([
            'data' => $endpoint->deliveries()
                ->orderByDesc('created_at')
                ->paginate((int) min(max($request->integer('per_page', 25), 1), 100))
                ->through(function ($delivery): array {
                    if (! $delivery instanceof WebhookDelivery) {
                        throw new \LogicException('Unexpected webhook delivery model.');
                    }

                    return $this->serializeDelivery($delivery);
                }),
        ]);
    }

    public function showDelivery(Request $request, string $webhook, string $delivery): JsonResponse
    {
        $endpoint = $this->owned($request, $webhook);
        $record = WebhookDelivery::query()
            ->whereKey($delivery)
            ->where('webhook_endpoint_id', $endpoint->id)
            ->firstOrFail();

        return response()->json(['data' => $this->serializeDelivery($record)]);
    }

    private function owned(Request $request, string $id): WebhookEndpoint
    {
        return WebhookEndpoint::query()
            ->with('user')
            ->whereKey($id)
            ->where('user_id', $this->owner($request)->id)
            ->firstOrFail();
    }

    private function owner(Request $request): User
    {
        return $request->attributes->get('apiKeyOwner');
    }

    /** @return array<string, mixed> */
    private function serialize(WebhookEndpoint $endpoint): array
    {
        $user = $endpoint->relationLoaded('user') ? $endpoint->user : null;

        return [
            'id' => $endpoint->id,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'active' => $endpoint->is_active,
            'inactive_due_to_plan' => $user instanceof User && ! $this->entitlements->allows($user, 'webhook.access'),
            'last_delivery_at' => $endpoint->last_delivery_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeDelivery(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'event_id' => $delivery->event_id,
            'event_type' => $delivery->event_type,
            'status' => $delivery->status,
            'attempt_count' => $delivery->attempt_count,
            'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
            'response_status' => $delivery->response_status,
            'failure_code' => $delivery->failure_code,
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'created_at' => $delivery->created_at?->toIso8601String(),
        ];
    }

    private function mapException(OutboundSendException $exception, User $user): JsonResponse
    {
        if ($exception->errorCode === 'plan_limit_reached') {
            $used = $this->webhooks->activeEndpointCount($user);
            $limit = $this->entitlements->limit($user, 'webhook.max_endpoints');

            return CommercialApiErrorMapper::fromOutboundSendException($exception, $used, $limit);
        }

        return CommercialApiErrorMapper::fromOutboundSendException($exception);
    }
}
