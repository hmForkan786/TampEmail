<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\CheckoutSessionResult;
use App\DTOs\Billing\CreateBillingOrderData;
use App\DTOs\Billing\CreateCheckoutData;
use App\DTOs\Billing\StartCheckoutData;
use App\Enums\BillingCheckoutSessionStatus;
use App\Enums\BillingOrderStatus;
use App\Enums\PaymentCapability;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Exceptions\Billing\CheckoutException;
use App\Models\BillingCheckoutRequest;
use App\Models\BillingCheckoutSession;
use App\Models\BillingOrder;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CheckoutService
{
    public function __construct(
        private readonly BillingOrderService $orders,
        private readonly PaymentGatewayResolver $gatewayResolver,
        private readonly CheckoutEligibilityService $eligibility,
        private readonly CheckoutRedirectPolicy $redirects,
        private readonly AuditLogWriter $audit,
    ) {}

    /** @return array{order: BillingOrder, checkout: CheckoutSessionResult} */
    public function start(CreateBillingOrderData $data, string $successUrl, string $cancelUrl, ?string $provider = null): array
    {
        $result = $this->startCheckout(new StartCheckoutData(
            userId: $data->userId,
            planId: $data->planId,
            gateway: $provider ?? $data->provider ?? (string) config('billing.default_gateway'),
            billingCycle: $data->billingCycle,
            idempotencyKey: $data->idempotencyKey,
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
        ));
        $session = $result['session'];

        return [
            'order' => $result['order'],
            'checkout' => new CheckoutSessionResult(
                $session->provider,
                (string) $session->provider_reference,
                (string) $session->checkout_url,
                $session->expires_at?->toIso8601String(),
            ),
        ];
    }

    /** @return array{order: BillingOrder, session: BillingCheckoutSession, reused: bool} */
    public function startCheckout(StartCheckoutData $data): array
    {
        $user = User::query()->findOrFail($data->userId);
        $this->audit->write('billing.checkout.requested', $data->userId, null, null, [
            'plan_id' => $data->planId, 'gateway' => strtolower(trim($data->gateway)),
        ]);
        try {
            $successUrl = $this->redirects->normalize($data->successUrl);
            $cancelUrl = $this->redirects->normalize($data->cancelUrl);
            $returnUrl = $this->redirects->normalize($data->returnUrl ?? '', true);
        } catch (CheckoutException $exception) {
            $this->audit->write('billing.checkout.redirect_rejected', $data->userId, null, null, [
                'plan_id' => $data->planId, 'gateway' => strtolower(trim($data->gateway)),
                'error_code' => $exception->errorCode,
            ]);
            throw $exception;
        }
        $gateway = $this->gatewayResolver->resolve($data->gateway, PaymentCapability::Checkout);
        $provider = $gateway->name();

        $plan = Plan::query()->findOrFail($data->planId);
        $eligibility = $this->eligibility->evaluate($user, $plan);
        if (! $eligibility->eligible || $eligibility->orderType === null) {
            throw new CheckoutException($eligibility->reasonCode ?? 'billing_unavailable', 'Checkout is not eligible.', 409);
        }

        $fingerprint = $this->fingerprint($data, $provider, $eligibility->orderType->value, $successUrl, $cancelUrl, $returnUrl);
        $preExisting = BillingCheckoutRequest::query()
            ->where('user_id', $data->userId)
            ->where('idempotency_key', $data->idempotencyKey)
            ->first();
        if ($preExisting instanceof BillingCheckoutRequest && ! hash_equals($preExisting->request_fingerprint, $fingerprint)) {
            $this->audit->write('billing.checkout.idempotency_conflict', $data->userId, $preExisting, null, [
                'gateway' => $provider, 'plan_id' => $data->planId, 'error_code' => 'idempotency_conflict',
            ]);
            throw new CheckoutException('idempotency_conflict', 'Idempotency conflict.', 409);
        }

        try {
            $reservation = DB::transaction(function () use ($data, $provider, $fingerprint): BillingCheckoutRequest {
                User::query()->whereKey($data->userId)->lockForUpdate()->firstOrFail();
                $existing = BillingCheckoutRequest::query()
                    ->where('user_id', $data->userId)
                    ->where('idempotency_key', $data->idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof BillingCheckoutRequest) {
                    if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
                        throw new CheckoutException('idempotency_conflict', 'Idempotency conflict.', 409);
                    }

                    return $existing;
                }

                return BillingCheckoutRequest::query()->create([
                    'user_id' => $data->userId,
                    'idempotency_key' => $data->idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'gateway' => $provider,
                    'status' => 'reserved',
                    'expires_at' => now()->addMinutes((int) config('billing.checkout.order_expiry_minutes', 30)),
                ]);
            });
        } catch (QueryException $exception) {
            $reservation = BillingCheckoutRequest::query()
                ->where('user_id', $data->userId)
                ->where('idempotency_key', $data->idempotencyKey)
                ->first();
            if (! $reservation instanceof BillingCheckoutRequest) {
                throw $exception;
            }
            if (! hash_equals($reservation->request_fingerprint, $fingerprint)) {
                throw new CheckoutException('idempotency_conflict', 'Idempotency conflict.', 409);
            }
        }

        if ($reservation->billing_order_id !== null) {
            $order = BillingOrder::query()->whereKey($reservation->billing_order_id)->where('user_id', $data->userId)->firstOrFail();
            $session = $this->activeSession($order);
            if ($session instanceof BillingCheckoutSession) {
                $this->audit->write('billing.checkout.reused', $data->userId, $order, null, [
                    'session_id' => $session->getKey(), 'gateway' => $provider, 'plan_id' => $data->planId,
                ]);

                return ['order' => $order, 'session' => $session, 'reused' => true];
            }
        }

        $order = $this->orders->create(new CreateBillingOrderData(
            userId: $data->userId,
            planId: $data->planId,
            type: $eligibility->orderType,
            billingCycle: $data->billingCycle,
            idempotencyKey: $data->idempotencyKey,
            provider: $provider,
            subscriptionId: $eligibility->subscriptionId,
        ));

        $session = DB::transaction(function () use ($reservation, $order, $data, $provider, $fingerprint): BillingCheckoutSession {
            $reservation = BillingCheckoutRequest::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();
            $reservation->forceFill(['billing_order_id' => $order->getKey(), 'status' => 'creating'])->save();

            return BillingCheckoutSession::query()->create([
                'billing_order_id' => $order->getKey(),
                'user_id' => $data->userId,
                'provider' => $provider,
                'status' => BillingCheckoutSessionStatus::Created,
                'request_fingerprint' => $fingerprint,
                'expires_at' => $order->expires_at,
                'metadata' => ['client_reference_hash' => $data->clientReference ? hash('sha256', $data->clientReference) : null],
            ]);
        });

        try {
            $result = $gateway->createCheckout(new CreateCheckoutData(
                billingOrderId: (string) $order->getKey(),
                userId: $data->userId,
                provider: $provider,
                amountMinor: $order->total_minor,
                currency: $order->currency,
                successUrl: $successUrl,
                cancelUrl: $cancelUrl,
                idempotencyKey: $data->idempotencyKey.':'.$session->getKey(),
            ));
            $this->validateProviderResult($result, $provider, $order);
        } catch (Throwable $exception) {
            $session->forceFill([
                'status' => BillingCheckoutSessionStatus::Failed,
                'last_error_code' => 'provider_session_failed',
                'last_error_message' => 'Provider checkout session creation failed.',
            ])->save();
            $reservation->forceFill(['status' => 'retryable'])->save();
            $this->audit->write('billing.checkout.session_failed', $data->userId, $order, null, [
                'session_id' => $session->getKey(), 'gateway' => $provider, 'error_code' => 'provider_session_failed',
            ]);
            if ($exception instanceof CheckoutException && $exception->errorCode === 'invalid_provider_response') {
                $this->audit->write('billing.checkout.provider_response_invalid', $data->userId, $order, null, [
                    'session_id' => $session->getKey(), 'gateway' => $provider,
                    'error_code' => $exception->errorCode,
                ]);
            }

            if ($exception instanceof CheckoutException) {
                throw $exception;
            }
            throw new CheckoutException('payment_gateway_unavailable', 'Provider checkout failed.', 502);
        }

        return DB::transaction(function () use ($result, $session, $reservation, $order, $data, $provider): array {
            $locked = BillingCheckoutSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'status' => BillingCheckoutSessionStatus::Pending,
                'provider_session_id' => $result->providerReference,
                'provider_reference' => $result->providerReference,
                'checkout_url' => $result->checkoutUrl,
                'expires_at' => $result->expiresAt ? CarbonImmutable::parse($result->expiresAt) : $order->expires_at,
            ])->save();
            $order->forceFill([
                'status' => BillingOrderStatus::Processing,
                'provider' => $provider,
                'provider_reference' => $result->providerReference,
            ])->save();
            PaymentTransaction::query()->firstOrCreate([
                'billing_order_id' => $order->getKey(),
                'idempotency_key' => $data->idempotencyKey.':pending-sale',
            ], [
                'user_id' => $data->userId,
                'provider' => $provider,
                'type' => PaymentTransactionType::Sale,
                'status' => PaymentTransactionStatus::Pending,
                'amount_minor' => $order->total_minor,
                'currency' => $order->currency,
                'provider_transaction_id' => $result->providerReference,
                'metadata' => ['checkout_session_id' => $locked->getKey()],
            ]);
            $reservation->forceFill(['status' => 'completed'])->save();
            $this->audit->write('billing.checkout.session_created', $data->userId, $order, null, [
                'session_id' => $locked->getKey(), 'gateway' => $provider, 'plan_id' => $data->planId,
            ]);

            return ['order' => $order->fresh(), 'session' => $locked->fresh(), 'reused' => false];
        });
    }

    /** @return array{order: BillingOrder, session: BillingCheckoutSession, reused: bool} */
    public function resume(BillingOrder $order, string $userId): array
    {
        $this->assertOwned($order, $userId);
        if ($order->status->isPaid() || in_array($order->status, [BillingOrderStatus::Refunded, BillingOrderStatus::ChargedBack], true)) {
            throw new CheckoutException('order_already_paid', 'Paid orders cannot be resumed.', 409);
        }
        if ($order->expires_at?->isPast() || $order->status === BillingOrderStatus::Expired) {
            throw new CheckoutException('checkout_expired', 'Checkout expired.', 410);
        }
        $this->orders->assertCheckoutEligible($order);
        $session = $this->activeSession($order);
        if (! $session instanceof BillingCheckoutSession || $session->checkout_url === null) {
            throw new CheckoutException('checkout_session_unavailable', 'No resumable checkout session exists.', 409);
        }
        $this->gatewayResolver->resolve($session->provider, PaymentCapability::Checkout);
        $this->audit->write('billing.checkout.resumed', $userId, $order, null, [
            'session_id' => $session->getKey(), 'gateway' => $session->provider,
        ]);

        return ['order' => $order, 'session' => $session, 'reused' => true];
    }

    public function cancel(BillingOrder $order, string $userId): BillingOrder
    {
        $this->assertOwned($order, $userId);
        if ($order->status->isPaid() || in_array($order->status, [BillingOrderStatus::Refunded, BillingOrderStatus::ChargedBack], true)) {
            throw new CheckoutException('order_already_paid', 'Paid orders cannot be cancelled.', 409);
        }
        $this->orders->assertCheckoutEligible($order);
        BillingCheckoutSession::query()
            ->where('billing_order_id', $order->getKey())
            ->whereIn('status', [BillingCheckoutSessionStatus::Created, BillingCheckoutSessionStatus::Pending, BillingCheckoutSessionStatus::Redirected])
            ->update(['status' => BillingCheckoutSessionStatus::Cancelled->value, 'updated_at' => now()]);
        $cancelled = $this->orders->transition($order, BillingOrderStatus::Cancelled, ['cancelled_at' => now()]);
        $this->audit->write('billing.checkout.cancelled', $userId, $cancelled, null, ['gateway' => $order->provider]);

        return $cancelled;
    }

    private function activeSession(BillingOrder $order): ?BillingCheckoutSession
    {
        return BillingCheckoutSession::query()
            ->where('billing_order_id', $order->getKey())
            ->whereIn('status', [BillingCheckoutSessionStatus::Pending, BillingCheckoutSessionStatus::Redirected])
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()->first();
    }

    private function assertOwned(BillingOrder $order, string $userId): void
    {
        if ($order->user_id !== $userId) {
            throw new CheckoutException('billing_order_not_found', 'Billing order not found.', 404);
        }
    }

    private function validateProviderResult(CheckoutSessionResult $result, string $provider, BillingOrder $order): void
    {
        if ($result->provider !== $provider || trim($result->providerReference) === '') {
            throw new CheckoutException('invalid_provider_response', 'Invalid provider result.', 502);
        }
        $this->redirects->assertProviderCheckoutUrl($result->checkoutUrl);
        if ($result->expiresAt !== null && CarbonImmutable::parse($result->expiresAt)->lessThanOrEqualTo(now())) {
            throw new CheckoutException('invalid_provider_response', 'Invalid provider expiry.', 502);
        }
        if ($order->total_minor < 1 || strlen($order->currency) !== 3) {
            throw new CheckoutException('invalid_provider_response', 'Invalid order snapshot.', 502);
        }
    }

    private function fingerprint(StartCheckoutData $data, string $provider, string $type, string $success, string $cancel, ?string $return): string
    {
        return hash('sha256', json_encode([
            'plan_id' => $data->planId,
            'gateway' => $provider,
            'billing_cycle' => $data->billingCycle->value,
            'order_type' => $type,
            'success_url' => $success,
            'cancel_url' => $cancel,
            'return_url' => $return,
            'client_reference' => $data->clientReference,
        ], JSON_THROW_ON_ERROR));
    }
}
