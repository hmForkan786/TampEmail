<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\QueryPaymentData;
use App\DTOs\Billing\VerifiedProviderEvent;
use App\Enums\BillingOrderStatus;
use App\Enums\PaymentCapability;
use App\Enums\PaymentTransactionType;
use App\Enums\ProviderPaymentStatus;
use App\Exceptions\Billing\PaymentVerificationException;
use App\Models\BillingOrder;
use App\Services\Audit\AuditLogWriter;

final class PaymentStatusSynchronizationService
{
    public function __construct(
        private readonly PaymentGatewayResolver $gateways,
        private readonly PaymentProcessingService $processing,
        private readonly AuditLogWriter $audit,
    ) {}

    /** @return array{order_id:string,payment_status:string,synchronization_status:string,last_checked_at:string} */
    public function sync(BillingOrder $order): array
    {
        if ($order->status->isTerminal() || $order->status === BillingOrderStatus::Paid) {
            return ['order_id' => (string) $order->getKey(), 'payment_status' => $order->status->value, 'synchronization_status' => 'not_required', 'last_checked_at' => now()->toIso8601String()];
        }
        if ($order->provider === null || $order->provider_reference === null) {
            throw new PaymentVerificationException('Order has no queryable provider reference.');
        }
        $gateway = $this->gateways->resolve($order->provider, PaymentCapability::PaymentQuery);
        $this->audit->write('billing.payment.status_sync_requested', $order->user_id, $order, null, ['provider' => $order->provider]);
        try {
            $result = $gateway->queryPayment(new QueryPaymentData(
                $order->provider, $order->provider_reference, (string) $order->getKey(),
                $order->total_minor, $order->currency,
            ));
            if ($result->billingOrderId !== $order->getKey() || $result->amountMinor !== $order->total_minor
                || strtoupper($result->currency) !== $order->currency) {
                throw new PaymentVerificationException('Malformed provider query result.');
            }
            $status = $result->status ?? ($result->succeeded ? ProviderPaymentStatus::Succeeded : ProviderPaymentStatus::Pending);
            $this->processing->processVerifiedPayment(new VerifiedProviderEvent(
                provider: $order->provider,
                providerEventId: $result->providerEventId ?? 'query_'.hash('sha256', $order->getKey().'|'.$order->provider_reference),
                eventType: 'payment.status_synchronized',
                providerTransactionId: $result->providerTransactionId,
                billingOrderId: (string) $order->getKey(),
                amountMinor: $result->amountMinor,
                currency: $result->currency,
                transactionType: PaymentTransactionType::Sale,
                succeeded: $result->succeeded,
                paymentStatus: $status,
                signatureVerified: true,
            ));
            $this->audit->write('billing.payment.status_sync_completed', $order->user_id, $order, null, ['status' => $status->value]);

            return ['order_id' => (string) $order->getKey(), 'payment_status' => $status->value, 'synchronization_status' => 'completed', 'last_checked_at' => now()->toIso8601String()];
        } catch (\Throwable $exception) {
            $this->audit->write('billing.payment.status_sync_failed', $order->user_id, $order, null, ['reason_code' => 'provider_query_failed']);
            throw $exception;
        }
    }

    public function syncStale(int $limit, int $staleMinutes): int
    {
        $count = 0;
        BillingOrder::query()->where('status', BillingOrderStatus::Processing)
            ->where('updated_at', '<=', now()->subMinutes(max(1, $staleMinutes)))
            ->limit(max(1, min(1000, $limit)))->get()
            ->each(function (BillingOrder $order) use (&$count): void {
                $this->sync($order);
                $count++;
            });

        return $count;
    }
}
