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
use App\Enums\ManualCryptoClaimState;
use App\Enums\ManualCryptoEvidenceStatus;
use App\Enums\PaymentCapability;
use App\Enums\PaymentSettlementStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\ProviderPaymentStatus;
use App\Exceptions\Billing\CheckoutException;
use App\Exceptions\Billing\PaymentVerificationException;
use App\Models\BillingOrder;
use App\Models\ManualCryptoCheckoutSnapshot;
use App\Models\ManualCryptoPaymentClaim;
use App\Services\Audit\AuditLogWriter;
use App\Services\Billing\ManualCrypto\ManualCryptoWalletResolver;
use Illuminate\Support\Facades\URL;

final readonly class ManualCryptoPaymentGateway implements PaymentGateway
{
    public function __construct(
        private ManualCryptoWalletResolver $wallets,
        private AuditLogWriter $audit,
    ) {}

    public function name(): string
    {
        return 'manual_crypto';
    }

    public function createCheckout(CreateCheckoutData $data): CheckoutSessionResult
    {
        if (! config('billing.manual_crypto.enabled', false) || $data->amountMinor <= 0
            || ! in_array(strtoupper($data->currency), (array) config('billing.manual_crypto.allowed_order_currencies', ['USD']), true)) {
            throw new CheckoutException('payment_gateway_unavailable', 'Manual crypto checkout is unavailable.', 422);
        }
        $order = BillingOrder::query()->findOrFail($data->billingOrderId);
        try {
            $wallet = $this->wallets->resolve();
        } catch (CheckoutException $exception) {
            $this->audit->write('manual_crypto.wallet_disabled', $data->userId, $order);
            throw $exception;
        }
        $snapshot = ManualCryptoCheckoutSnapshot::query()->firstOrCreate(
            ['billing_order_id' => $order->getKey()],
            [
                'wallet_id' => $wallet->id, 'wallet_address' => $wallet->address,
                'asset' => 'USDT', 'network' => 'TRC20',
                'expected_amount_minor' => $data->amountMinor,
                'currency' => strtoupper($data->currency),
                'expires_at' => $order->expires_at ?? now()->addMinutes((int) config('billing.order_expiry_minutes', 30)),
            ],
        );
        $url = URL::temporarySignedRoute('billing.manual-crypto.instructions', $snapshot->expires_at, ['snapshot' => $snapshot->getKey()]);

        return new CheckoutSessionResult($this->name(), (string) $snapshot->getKey(), $url, $snapshot->expires_at->toIso8601String());
    }

    public function verifyWebhook(WebhookRequestData $data): VerifiedProviderEvent
    {
        $claim = ManualCryptoPaymentClaim::query()->with(['order', 'snapshot'])->find((string) ($data->payload['claim_id'] ?? ''));
        $eventId = (string) ($data->payload['event_id'] ?? '');
        if (! $claim instanceof ManualCryptoPaymentClaim
            || $claim->state !== ManualCryptoClaimState::Approved
            || $claim->evidence_status !== ManualCryptoEvidenceStatus::ManuallyVerified
            || $eventId === '' || ! hash_equals((string) $claim->provider_event_id, $eventId)) {
            throw new PaymentVerificationException('Manual crypto claim is not approved.');
        }

        return new VerifiedProviderEvent(
            provider: $this->name(), providerEventId: $eventId,
            eventType: 'manual_crypto.payment.approved', providerTransactionId: $claim->txid,
            billingOrderId: (string) $claim->billing_order_id,
            amountMinor: (int) $claim->snapshot->expected_amount_minor,
            currency: (string) $claim->snapshot->currency,
            transactionType: PaymentTransactionType::Sale, succeeded: true,
            paymentStatus: ProviderPaymentStatus::Succeeded,
            providerOrderReference: (string) $claim->billing_order_id,
            providerSessionId: (string) $claim->checkout_snapshot_id,
            occurredAt: $claim->approved_at?->toIso8601String(),
            settlementStatus: PaymentSettlementStatus::Settled,
            settlementReference: $claim->txid,
            rawPayloadFingerprint: hash('sha256', $data->rawBody),
            signatureVerified: true,
            safeMetadata: ['asset' => 'USDT', 'network' => 'TRC20', 'evidence_status' => 'manually_verified', 'claim_id' => $claim->getKey()],
        );
    }

    public function queryPayment(QueryPaymentData $data): PaymentQueryResult
    {
        $claim = ManualCryptoPaymentClaim::query()->where('billing_order_id', $data->billingOrderId)->first();
        if (! $claim instanceof ManualCryptoPaymentClaim) {
            return new PaymentQueryResult(
                $data->providerTransactionId,
                $data->billingOrderId,
                $data->expectedAmountMinor ?? 0,
                $data->expectedCurrency ?? 'USD',
                false,
                ProviderPaymentStatus::Pending,
                settlementStatus: PaymentSettlementStatus::Pending->value,
            );
        }
        $succeeded = $claim->state === ManualCryptoClaimState::Approved;

        return new PaymentQueryResult(
            $claim->txid, $data->billingOrderId,
            $data->expectedAmountMinor ?? 0, $data->expectedCurrency ?? 'USD', $succeeded,
            $succeeded ? ProviderPaymentStatus::Succeeded : ProviderPaymentStatus::Pending,
            $succeeded ? $claim->provider_event_id : null,
            $succeeded ? PaymentSettlementStatus::Settled->value : PaymentSettlementStatus::Pending->value,
        );
    }

    public function refund(RefundPaymentData $data): RefundResult
    {
        throw new PaymentVerificationException('Manual crypto refunds are not supported.');
    }

    public function supports(PaymentCapability $capability): bool
    {
        return in_array($capability, [PaymentCapability::Checkout, PaymentCapability::PaymentQuery, PaymentCapability::WebhookVerification], true);
    }
}
