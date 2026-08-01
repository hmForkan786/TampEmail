<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentProviderEventStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
