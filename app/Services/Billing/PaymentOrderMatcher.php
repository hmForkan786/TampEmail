<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\VerifiedProviderEvent;
use App\Exceptions\Billing\BillingOrderNotFoundException;
use App\Exceptions\Billing\PaymentVerificationException;
use App\Models\BillingCheckoutSession;
use App\Models\BillingOrder;
use App\Models\PaymentTransaction;

final class PaymentOrderMatcher
{
    public function match(VerifiedProviderEvent $event): BillingOrder
    {
        $candidates = collect();
        if ($event->billingOrderId !== '') {
            $candidates->push(BillingOrder::query()->find($event->billingOrderId));
        }
        if ($event->providerOrderReference) {
            $candidates->push(BillingOrder::query()->where('provider', $event->provider)
                ->where('provider_reference', $event->providerOrderReference)->first());
        }
        if ($event->providerSessionId) {
            $session = BillingCheckoutSession::query()->where('provider', $event->provider)
                ->where('provider_session_id', $event->providerSessionId)->first();
            $candidates->push($session?->order);
        }
        $candidates->push(PaymentTransaction::query()->where('provider', $event->provider)
            ->where('provider_transaction_id', $event->providerTransactionId)->first()?->billingOrder);
        $orders = $candidates->filter()->unique(fn (BillingOrder $order): string => (string) $order->getKey())->values();
        if ($orders->count() !== 1) {
            if ($orders->count() > 1) {
                throw new PaymentVerificationException('Ambiguous provider order reference.');
            }
            throw new BillingOrderNotFoundException('Billing order not found for provider event.');
        }
        $order = $orders->first();
        if ($order->provider !== null && $order->provider !== $event->provider) {
            throw new PaymentVerificationException('Provider mismatch.');
        }

        return $order;
    }
}
