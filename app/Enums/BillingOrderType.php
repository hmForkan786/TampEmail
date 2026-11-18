<?php

declare(strict_types=1);

namespace App\Enums;

enum BillingOrderType: string
{
    case Purchase = 'purchase';
    case Renewal = 'renewal';
    case Upgrade = 'upgrade';
    case Downgrade = 'downgrade';
}
