<?php

declare(strict_types=1);

namespace App\Services\Billing\Gateways;

use App\Contracts\Billing\PaymentGateway;
use App\DTOs\Billing\CheckoutSessionResult;
use App\DTOs\Billing\CreateCheckoutData;
use App\DTOs\Billing\PaymentQueryResult;
use App\DTOs\Billing\QueryPaymentData;
use App\DTOs\Billing\RefundPaymentData;
use App\DTOs\Billing\RefundResult;
use App\DTOs\Billing\VerifiedProviderEvent;
use App\DTOs\Billing\WebhookRequestData;
use App\Enums\PaymentCapability;
use App\Enums\PaymentTransactionType;
use Illuminate\Support\Str;

/** Test and architecture placeholder gateway — not a production provider. */
final class FakePaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function createCheckout(CreateCheckoutData $data): CheckoutSessionResult
    {
        $reference = 'fake_chk_'.Str::uuid()->toString();

        return new CheckoutSessionResult(
            provider: $this->name(),
            providerReference: $reference,
            checkoutUrl: 'https://checkout.example.test/'.$reference,
            expiresAt: now()->addMinutes(30)->toIso8601String(),
        );
    }

    public function verifyWebhook(WebhookRequestData $data): VerifiedProviderEvent
    {
        $payload = $data->payload;

        return new VerifiedProviderEvent(
            provider: $this->name(),
            providerEventId: (string) ($payload['event_id'] ?? 'fake_evt_'.hash('sha256', $data->rawBody)),
            eventType: (string) ($payload['event_type'] ?? 'payment.updated'),
            providerTransactionId: (string) ($payload['provider_transaction_id'] ?? 'fake_tx_unknown'),
            billingOrderId: (string) ($payload['billing_order_id'] ?? ''),
            amountMinor: (int) ($payload['amount_minor'] ?? 0),
            currency: strtoupper((string) ($payload['currency'] ?? 'USD')),
            transactionType: PaymentTransactionType::Sale,
            succeeded: (bool) ($payload['succeeded'] ?? false),
            failureCode: isset($payload['failure_code']) ? (string) $payload['failure_code'] : null,
            failureMessage: isset($payload['failure_message']) ? (string) $payload['failure_message'] : null,
        );
    }

    public function queryPayment(QueryPaymentData $data): PaymentQueryResult
    {
        return new PaymentQueryResult(
            providerTransactionId: $data->providerTransactionId,
            billingOrderId: $data->billingOrderId,
            amountMinor: 0,
            currency: 'USD',
            succeeded: false,
        );
    }

    public function refund(RefundPaymentData $data): RefundResult
    {
        return new RefundResult(
            providerRefundId: 'fake_ref_'.Str::uuid()->toString(),
            amountMinor: $data->amountMinor,
            currency: $data->currency,
            succeeded: true,
        );
    }

    public function supports(PaymentCapability $capability): bool
    {
        return true;
    }
}
