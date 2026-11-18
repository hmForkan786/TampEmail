<?php

declare(strict_types=1);

use App\DTOs\Billing\WebhookRequestData;
use App\Enums\BillingOrderStatus;
use App\Enums\ManualCryptoClaimState;
use App\Enums\ManualCryptoEvidenceStatus;
use App\Enums\PaymentCapability;
use App\Enums\PlatformRole;
use App\Exceptions\Billing\CheckoutException;
use App\Jobs\Billing\ActivatePaidSubscriptionJob;
use App\Models\AuditLog;
use App\Models\PaymentProviderEvent;
use App\Models\User;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\Gateways\ManualCryptoPaymentGateway;
use App\Services\Billing\ManualCrypto\ManualCryptoAmount;
use App\Services\Billing\ManualCrypto\ManualCryptoClaimService;
use App\Services\Billing\ManualCrypto\ManualCryptoReviewService;
use App\Services\Billing\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';

beforeEach(function (): void {
    URL::forceRootUrl('https://app.test');
    URL::forceScheme('https');
    config()->set('billing.enabled_gateways', ['fake', 'manual_crypto']);
    config()->set('billing.manual_crypto.enabled', true);
    config()->set('billing.manual_crypto.wallets.primary', [
        'id' => 'primary', 'network' => 'TRC20',
        'address' => 'TYMwiN8i4hGHJtK4CZv4q1xxR8YV6zKN9A',
        'enabled' => true, 'priority' => 100, 'rotation_group' => 'default',
    ]);
});

function manualCryptoCheckout(string $idempotency = 'manual-crypto-1'): array
{
    [$user, $plan] = billingPremiumContext();
    $result = app(CheckoutService::class)->start(
        billingOrderData($user, $plan, $idempotency),
        'https://app.test/success', 'https://app.test/cancel', 'manual_crypto',
    );

    return [$user, $result['order'], $result['checkout']];
}

it('creates an idempotent checkout with an encrypted immutable wallet snapshot', function (): void {
    [$user, $order, $checkout] = manualCryptoCheckout();
    $again = app(CheckoutService::class)->start(
        billingOrderData($user, $order->plan, 'manual-crypto-1'),
        'https://app.test/success', 'https://app.test/cancel', 'manual_crypto',
    );

    expect($checkout->provider)->toBe('manual_crypto')
        ->and($checkout->checkoutUrl)->toContain('/billing/manual-crypto/')
        ->and($again['order']->getKey())->toBe($order->getKey())
        ->and($order->status)->toBe(BillingOrderStatus::Processing)
        ->and($order->provider)->toBe('manual_crypto');
});

it('submits normalized unique TRC20 claims and treats screenshots as evidence only', function (): void {
    [$user, $order] = manualCryptoCheckout();
    $txid = strtoupper(str_repeat('ab', 32));
    $amount = ManualCryptoAmount::format(ManualCryptoAmount::expectedUnits($order->total_minor));
    $claim = app(ManualCryptoClaimService::class)->submit((string) $order->getKey(), (string) $user->getKey(), $txid, $amount, null);

    expect($claim->txid)->toBe(strtolower($txid))
        ->and($claim->state)->toBe(ManualCryptoClaimState::Submitted)
        ->and($claim->evidence_status)->toBe(ManualCryptoEvidenceStatus::Submitted)
        ->and($order->fresh()->status)->toBe(BillingOrderStatus::Processing);

    expect(fn () => app(ManualCryptoClaimService::class)->submit(
        (string) $order->getKey(), (string) $user->getKey(), $txid, $amount, null,
    ))->toThrow(CheckoutException::class);
});

it('routes authorized manual approval through Prompt 638 and activates once', function (): void {
    Queue::fake();
    [$user, $order] = manualCryptoCheckout();
    $admin = User::factory()->create(['platform_role' => PlatformRole::Admin]);
    $amount = ManualCryptoAmount::format(ManualCryptoAmount::expectedUnits($order->total_minor));
    $claim = app(ManualCryptoClaimService::class)->submit(
        (string) $order->getKey(), (string) $user->getKey(), str_repeat('c', 64), $amount, null,
    );

    $approved = app(ManualCryptoReviewService::class)->approve($claim, $admin, 'TXID and amount manually checked.');
    $event = PaymentProviderEvent::query()->where('provider', 'manual_crypto')->firstOrFail();
    $payload = ['claim_id' => $approved->getKey(), 'event_id' => $approved->provider_event_id];
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $verified = app(ManualCryptoPaymentGateway::class)->verifyWebhook(new WebhookRequestData('manual_crypto', [], $payload, $raw));
    app(PaymentProcessingService::class)->processStoredEvent($event, $verified);

    expect($approved->evidence_status)->toBe(ManualCryptoEvidenceStatus::ManuallyVerified)
        ->and($order->fresh()->status)->toBe(BillingOrderStatus::Paid)
        ->and(AuditLog::query()->where('action', 'manual_crypto.approved')->count())->toBe(1);
    Queue::assertPushed(ActivatePaidSubscriptionJob::class, 1);
});

it('records rejection and supports controlled reopening without financial mutation', function (): void {
    [$user, $order] = manualCryptoCheckout();
    $admin = User::factory()->create(['platform_role' => PlatformRole::Admin]);
    $claim = app(ManualCryptoClaimService::class)->submit(
        (string) $order->getKey(), (string) $user->getKey(), str_repeat('d', 64),
        ManualCryptoAmount::format(ManualCryptoAmount::expectedUnits($order->total_minor)), null,
    );
    $rejected = app(ManualCryptoReviewService::class)->reject($claim, $admin, 'Transaction evidence is insufficient.');
    $reopened = app(ManualCryptoReviewService::class)->reopen($rejected, $admin, 'Additional evidence received.');

    expect($rejected->state)->toBe(ManualCryptoClaimState::Rejected)
        ->and($reopened->state)->toBe(ManualCryptoClaimState::UnderReview)
        ->and($reopened->reviewEvents)->toHaveCount(3)
        ->and($order->fresh()->status)->toBe(BillingOrderStatus::Processing);
});

it('forbids self approval and explicitly disables refunds', function (): void {
    [$user, $order] = manualCryptoCheckout();
    $user->forceFill(['platform_role' => PlatformRole::Admin])->save();
    $claim = app(ManualCryptoClaimService::class)->submit(
        (string) $order->getKey(), (string) $user->getKey(), str_repeat('e', 64),
        ManualCryptoAmount::format(ManualCryptoAmount::expectedUnits($order->total_minor)), null,
    );

    expect(fn () => app(ManualCryptoReviewService::class)->approve($claim, $user, 'Self review.'))
        ->toThrow(CheckoutException::class)
        ->and(app(ManualCryptoPaymentGateway::class)->supports(PaymentCapability::Refund))->toBeFalse();
});

it('rejects review attempts from an unprivileged user', function (): void {
    [$user, $order] = manualCryptoCheckout();
    $outsider = User::factory()->create();
    $claim = app(ManualCryptoClaimService::class)->submit(
        (string) $order->getKey(), (string) $user->getKey(), str_repeat('f', 64),
        ManualCryptoAmount::format(ManualCryptoAmount::expectedUnits($order->total_minor)), null,
    );

    expect(fn () => app(ManualCryptoReviewService::class)->reject($claim, $outsider, 'Unauthorized review.'))
        ->toThrow(CheckoutException::class);
});
