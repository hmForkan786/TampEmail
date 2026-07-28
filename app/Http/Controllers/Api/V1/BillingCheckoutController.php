<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Billing\StartCheckoutData;
use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StartCheckoutRequest;
use App\Models\BillingCheckoutSession;
use App\Models\BillingOrder;
use App\Models\User;
use App\Services\Billing\BillingOrderQueryService;
use App\Services\Billing\BillingPaymentStatusService;
use App\Services\Billing\BillingResponseFactory;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\PaymentStatusSynchronizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class BillingCheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly BillingOrderQueryService $orders,
        private readonly BillingResponseFactory $responses,
        private readonly BillingPaymentStatusService $paymentStatus,
        private readonly PaymentStatusSynchronizationService $synchronization,
    ) {}

    public function store(StartCheckoutRequest $request): JsonResponse
    {
        try {
            $result = $this->checkout->startCheckout(new StartCheckoutData(
                userId: (string) $this->user($request)->getKey(),
                planId: $request->string('plan_id')->toString(),
                gateway: $request->string('gateway')->toString(),
                billingCycle: BillingCycle::from($request->string('billing_cycle', 'monthly')->toString()),
                idempotencyKey: $request->string('idempotency_key')->toString(),
                successUrl: $request->string('success_url')->toString(),
                cancelUrl: $request->string('cancel_url')->toString(),
                returnUrl: $request->filled('return_url') ? $request->string('return_url')->toString() : null,
                clientReference: $request->filled('client_reference') ? $request->string('client_reference')->toString() : null,
                metadata: (array) $request->validated('metadata', []),
            ));

            return response()->json(['data' => $this->checkoutPayload($result['order'], $result['session'])], $result['reused'] ? 200 : 201);
        } catch (Throwable $exception) {
            return $this->responses->fromThrowable($exception);
        }
    }

    public function show(Request $request, string $billingOrder): JsonResponse
    {
        $order = $this->orders->owned($billingOrder, (string) $this->user($request)->getKey());

        return response()->json(['data' => array_merge([
            'id' => $order->getKey(),
            'type' => $order->type->value,
            'order_status' => $order->status->value,
            'plan' => ['id' => $order->plan_id, 'name' => $order->plan->name],
            'currency' => $order->currency,
            'subtotal_minor' => $order->subtotal_minor,
            'discount_minor' => $order->discount_minor,
            'tax_minor' => $order->tax_minor,
            'total_minor' => $order->total_minor,
            'paid_at' => $order->paid_at?->toIso8601String(),
            'expires_at' => $order->expires_at?->toIso8601String(),
            'subscription_id' => $order->subscription_id,
        ], $this->paymentStatus->project($order))]);
    }

    public function resume(Request $request, string $billingOrder): JsonResponse
    {
        try {
            $userId = (string) $this->user($request)->getKey();
            $order = $this->orders->owned($billingOrder, $userId);
            $result = $this->checkout->resume($order, $userId);

            return response()->json(['data' => $this->checkoutPayload($result['order'], $result['session'])]);
        } catch (Throwable $exception) {
            return $this->responses->fromThrowable($exception);
        }
    }

    public function cancel(Request $request, string $billingOrder): JsonResponse
    {
        try {
            $userId = (string) $this->user($request)->getKey();
            $order = $this->orders->owned($billingOrder, $userId);
            $cancelled = $this->checkout->cancel($order, $userId);

            return response()->json(['data' => ['id' => $cancelled->getKey(), 'status' => $cancelled->status->value]]);
        } catch (Throwable $exception) {
            return $this->responses->fromThrowable($exception);
        }
    }

    public function sync(Request $request, string $billingOrder): JsonResponse
    {
        try {
            $order = $this->orders->owned($billingOrder, (string) $this->user($request)->getKey());

            return response()->json(['data' => $this->synchronization->sync($order)]);
        } catch (Throwable $exception) {
            return $this->responses->fromThrowable($exception);
        }
    }

    /** @return array<string, mixed> */
    private function checkoutPayload(BillingOrder $order, BillingCheckoutSession $session): array
    {
        return [
            'order_id' => $order->getKey(),
            'checkout_session_id' => $session->getKey(),
            'status' => $session->status->value,
            'gateway' => $session->provider,
            'currency' => $order->currency,
            'subtotal_minor' => $order->subtotal_minor,
            'discount_minor' => $order->discount_minor,
            'tax_minor' => $order->tax_minor,
            'total_minor' => $order->total_minor,
            'checkout_url' => $session->checkout_url,
            'expires_at' => $session->expires_at?->toIso8601String(),
        ];
    }

    private function user(Request $request): User
    {
        return $request->attributes->get('apiKey')->user;
    }
}
