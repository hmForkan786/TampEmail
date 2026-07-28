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
use App\Enums\PaymentSettlementStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\ProviderPaymentStatus;
use App\Exceptions\Billing\PaymentVerificationException;
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
        if (config('billing.fake.require_signature', false)) {
            $provided = (string) ($data->headers['x-fake-signature'][0] ?? $data->headers['x-fake-signature'] ?? '');
            $expected = hash_hmac('sha256', $data->rawBody, (string) config('billing.fake.callback_secret'));
            if ($provided === '' || ! hash_equals($expected, $provided)) {
                throw new PaymentVerificationException('Invalid callback signature.');
            }
        }
        $payload = $data->payload;
        $status = ProviderPaymentStatus::tryFrom(strtolower((string) ($payload['payment_status'] ?? '')))
            ?? ((bool) ($payload['succeeded'] ?? false) ? ProviderPaymentStatus::Succeeded : ProviderPaymentStatus::Failed);
        $type = PaymentTransactionType::tryFrom(strtolower((string) ($payload['transaction_type'] ?? 'sale')))
            ?? PaymentTransactionType::Sale;

        return new VerifiedProviderEvent(
            provider: $this->name(),
            providerEventId: (string) ($payload['event_id'] ?? 'fake_evt_'.hash('sha256', $data->rawBody)),
            eventType: (string) ($payload['event_type'] ?? 'payment.updated'),
            providerTransactionId: (string) ($payload['provider_transaction_id'] ?? 'fake_tx_unknown'),
            billingOrderId: (string) ($payload['billing_order_id'] ?? ''),
            amountMinor: (int) ($payload['amount_minor'] ?? 0),
            currency: strtoupper((string) ($payload['currency'] ?? 'USD')),
            transactionType: $type,
            succeeded: $status->isFinancialSuccess(),
            failureCode: isset($payload['failure_code']) ? (string) $payload['failure_code'] : null,
            failureMessage: isset($payload['failure_message']) ? (string) $payload['failure_message'] : null,
            paymentStatus: $status,
            providerOrderReference: isset($payload['provider_order_reference']) ? (string) $payload['provider_order_reference'] : null,
            providerSessionId: isset($payload['provider_session_id']) ? (string) $payload['provider_session_id'] : null,
            occurredAt: isset($payload['occurred_at']) ? (string) $payload['occurred_at'] : null,
            settlementStatus: isset($payload['settlement_status']) ? PaymentSettlementStatus::tryFrom((string) $payload['settlement_status']) : null,
            settlementReference: isset($payload['settlement_reference']) ? (string) $payload['settlement_reference'] : null,
            rawPayloadFingerprint: hash('sha256', $data->rawBody),
            signatureVerified: true,
        );
    }

    public function queryPayment(QueryPaymentData $data): PaymentQueryResult
    {
        return new PaymentQueryResult(
            providerTransactionId: $data->providerTransactionId,
            billingOrderId: $data->billingOrderId,
            amountMinor: $data->expectedAmountMinor ?? 0,
            currency: $data->expectedCurrency ?? 'USD',
            succeeded: str_contains($data->providerTransactionId, 'success'),
            status: str_contains($data->providerTransactionId, 'success') ? ProviderPaymentStatus::Succeeded : ProviderPaymentStatus::Pending,
            providerEventId: 'fake_query_'.hash('sha256', $data->billingOrderId.'|'.$data->providerTransactionId),
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
