<?php

use App\DTOs\Billing\CreateBillingOrderData;
use App\DTOs\Billing\WebhookRequestData;
use App\Enums\BillingCycle;
use App\Enums\BillingOrderType;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\CommercialPlanFeatureSeeder;

function billingPremiumContext(): array
{
    app(CommercialPlanFeatureSeeder::class)->run();
    $user = User::factory()->create();
    $plan = Plan::query()->where('slug', 'premium')->firstOrFail();

    return [$user, $plan];
}

function billingOrderData(User $user, Plan $plan, string $idempotencyKey = 'order-1'): CreateBillingOrderData
{
    return new CreateBillingOrderData(
        userId: (string) $user->getKey(),
        planId: (string) $plan->getKey(),
        type: BillingOrderType::Purchase,
        billingCycle: BillingCycle::Monthly,
        idempotencyKey: $idempotencyKey,
    );
}

function billingSuccessWebhook(string $billingOrderId, int $amountMinor, string $currency = 'USD', string $eventId = 'evt-1'): WebhookRequestData
{
    $payload = [
        'event_id' => $eventId,
        'event_type' => 'payment.succeeded',
        'billing_order_id' => $billingOrderId,
        'amount_minor' => $amountMinor,
        'currency' => $currency,
        'succeeded' => true,
        'provider_transaction_id' => 'fake_tx_'.$eventId,
    ];

    return new WebhookRequestData(
        provider: 'fake',
        headers: [],
        payload: $payload,
        rawBody: json_encode($payload, JSON_THROW_ON_ERROR),
    );
}
