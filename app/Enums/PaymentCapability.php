<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentCapability: string
{
    case Checkout = 'checkout';
    case WebhookVerification = 'webhook_verification';
    case PaymentQuery = 'payment_query';
    case Refund = 'refund';
}
