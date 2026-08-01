<?php

declare(strict_types=1);

namespace App\Enums;

enum BillingActivationStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case ReconciliationRequired = 'reconciliation_required';
}
