<?php

declare(strict_types=1);

namespace App\Enums;

enum OutboundDomainAuthState: string
{
    case Unconfigured = 'unconfigured';
    case Pending = 'pending';
    case Verified = 'verified';
    case Degraded = 'degraded';
    case Failed = 'failed';

    public function allowsSending(bool $allowDegradedDmarc = true): bool
    {
        return match ($this) {
            self::Verified => true,
            self::Degraded => $allowDegradedDmarc,
            default => false,
        };
    }
}
