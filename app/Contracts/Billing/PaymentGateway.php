<?php

declare(strict_types=1);

namespace App\Contracts\Billing;

use App\DTOs\Billing\CheckoutSessionResult;
use App\DTOs\Billing\CreateCheckoutData;
use App\DTOs\Billing\PaymentQueryResult;
use App\DTOs\Billing\QueryPaymentData;
use App\DTOs\Billing\RefundPaymentData;
use App\DTOs\Billing\RefundResult;
use App\DTOs\Billing\VerifiedProviderEvent;
use App\DTOs\Billing\WebhookRequestData;
use App\Enums\PaymentCapability;

interface PaymentGateway
{
    public function name(): string;

    public function createCheckout(CreateCheckoutData $data): CheckoutSessionResult;

    public function verifyWebhook(WebhookRequestData $data): VerifiedProviderEvent;

    public function queryPayment(QueryPaymentData $data): PaymentQueryResult;

    public function refund(RefundPaymentData $data): RefundResult;

    public function supports(PaymentCapability $capability): bool;
}
