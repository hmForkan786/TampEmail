<?php

declare(strict_types=1);

namespace App\Enums;

enum BillingCheckoutSessionStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case Redirected = 'redirected';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
