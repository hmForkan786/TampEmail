<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\CheckoutSessionResult;
use App\DTOs\Billing\CreateBillingOrderData;
use App\DTOs\Billing\CreateCheckoutData;
use App\Enums\BillingOrderStatus;
use App\Enums\PaymentCapability;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\BillingOrder;
use App\Models\PaymentTransaction;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;

final class CheckoutService
{
    public function __construct(
        private readonly BillingOrderService $orders,
        private readonly PaymentGatewayResolver $gatewayResolver,
        private readonly AuditLogWriter $audit,
    ) {}

    /** @return array{order: BillingOrder, checkout: CheckoutSessionResult} */
    public function start(CreateBillingOrderData $orderData, string $successUrl, string $cancelUrl, ?string $provider = null): array
    {
        $order = $this->orders->create($orderData);
        $this->orders->assertCheckoutEligible($order);

        return DB::transaction(function () use ($order, $orderData, $successUrl, $cancelUrl, $provider): array {
            $locked = BillingOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->orders->assertCheckoutEligible($locked);

            $gateway = $this->gatewayResolver->resolve($provider, PaymentCapability::Checkout);
            $providerSlug = $gateway->name();

            $checkout = $gateway->createCheckout(new CreateCheckoutData(
                billingOrderId: (string) $locked->getKey(),
                userId: $orderData->userId,
                provider: $providerSlug,
                amountMinor: $locked->total_minor,
                currency: $locked->currency,
                successUrl: $successUrl,
                cancelUrl: $cancelUrl,
                idempotencyKey: $orderData->idempotencyKey.':checkout',
            ));

            $locked->forceFill([
                'status' => BillingOrderStatus::Processing,
                'provider' => $providerSlug,
                'provider_reference' => $checkout->providerReference,
            ])->save();

            PaymentTransaction::query()->create([
                'billing_order_id' => $locked->getKey(),
                'user_id' => $orderData->userId,
                'provider' => $providerSlug,
                'type' => PaymentTransactionType::Sale,
                'status' => PaymentTransactionStatus::Pending,
                'amount_minor' => $locked->total_minor,
                'currency' => $locked->currency,
                'provider_transaction_id' => $checkout->providerReference,
                'idempotency_key' => $orderData->idempotencyKey.':pending-sale',
                'metadata' => ['checkout_url' => $checkout->checkoutUrl],
            ]);

            $this->audit->write('billing.checkout.created', $orderData->userId, $locked, null, [
                'provider' => $providerSlug,
                'provider_reference' => $checkout->providerReference,
            ]);

            return [
                'order' => $locked->fresh() ?? $locked,
                'checkout' => $checkout,
            ];
        });
    }
}
