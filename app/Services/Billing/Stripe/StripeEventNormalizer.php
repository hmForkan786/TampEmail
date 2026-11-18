<?php

declare(strict_types=1);

namespace App\Services\Billing\Stripe;

use App\DTOs\Billing\VerifiedProviderEvent;
use App\Enums\PaymentTransactionType;
use App\Enums\ProviderPaymentStatus;
use App\Exceptions\Billing\PaymentVerificationException;
use App\Models\BillingOrder;

final class StripeEventNormalizer
{
    /** @param array<string, mixed> $event */
    public function normalize(array $event, string $rawBody): VerifiedProviderEvent
    {
        $eventId = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        $object = $event['data']['object'] ?? null;
        if ($eventId === '' || ! is_array($object)) {
            throw new PaymentVerificationException('Malformed Stripe event.');
        }
        $allowed = [
            'checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed',
            'payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.canceled',
        ];
        if (! in_array($type, $allowed, true)) {
            throw new PaymentVerificationException('Unsupported Stripe event.');
        }
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $orderId = (string) ($metadata['billing_order_id'] ?? $object['client_reference_id'] ?? '');
        $order = BillingOrder::query()->findOrFail($orderId);
        $orderMetadata = is_array($order->metadata) ? $order->metadata : [];
        if (($orderMetadata['stripe_account_key'] ?? null) !== ($metadata['account_key'] ?? null)) {
            throw new PaymentVerificationException('Stripe account mismatch.');
        }
        $isIntent = str_starts_with($type, 'payment_intent.');
        $intentId = $isIntent ? (string) ($object['id'] ?? '') : (string) ($object['payment_intent'] ?? '');
        $sessionId = $isIntent ? (string) ($metadata['checkout_session_id'] ?? $order->provider_reference) : (string) ($object['id'] ?? '');
        if ($intentId === '' || ($order->provider_reference !== null && $sessionId !== '' && ! hash_equals($order->provider_reference, $sessionId))) {
            throw new PaymentVerificationException('Stripe reference mismatch.');
        }
        $amount = (int) ($isIntent ? ($object['amount_received'] ?? $object['amount'] ?? -1) : ($object['amount_total'] ?? -1));
        $currency = strtoupper((string) ($object['currency'] ?? ''));
        if ($amount !== $order->total_minor || $currency !== $order->currency) {
            throw new PaymentVerificationException('Stripe money mismatch.');
        }
        $status = $this->status($type, (string) ($object['status'] ?? $object['payment_status'] ?? ''));

        return new VerifiedProviderEvent(
            'stripe', $eventId, $type, $intentId, (string) $order->getKey(), $amount, $currency,
            PaymentTransactionType::Sale, $status->isFinancialSuccess(), paymentStatus: $status,
            providerOrderReference: $sessionId, providerSessionId: $sessionId,
            occurredAt: isset($event['created']) ? date(DATE_ATOM, (int) $event['created']) : null,
            rawPayloadFingerprint: hash('sha256', $rawBody), signatureVerified: true,
            safeMetadata: ['account_key' => (string) ($metadata['account_key'] ?? '')],
        );
    }

    public function status(string $eventType, string $stripeStatus): ProviderPaymentStatus
    {
        if (in_array($eventType, ['payment_intent.succeeded', 'checkout.session.async_payment_succeeded'], true)
            || ($eventType === 'checkout.session.completed' && $stripeStatus === 'paid')) {
            return ProviderPaymentStatus::Succeeded;
        }

        return match ($stripeStatus) {
            'succeeded', 'paid' => ProviderPaymentStatus::Succeeded,
            'requires_capture' => ProviderPaymentStatus::Authorized,
            'canceled', 'cancelled' => ProviderPaymentStatus::Cancelled,
            'requires_payment_method', 'payment_failed', 'failed', 'unpaid' => ProviderPaymentStatus::Failed,
            'requires_confirmation', 'requires_action', 'processing', 'open', 'complete', 'no_payment_required' => ProviderPaymentStatus::Pending,
            default => str_contains($eventType, 'failed') ? ProviderPaymentStatus::Failed : ProviderPaymentStatus::Unknown,
        };
    }
}
