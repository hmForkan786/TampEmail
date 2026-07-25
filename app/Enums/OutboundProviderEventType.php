<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Normalized outbound provider delivery-event types.
 */
enum OutboundProviderEventType: string
{
    case Accepted = 'accepted';
    case Delivered = 'delivered';
    case TemporaryFailure = 'temporary_failure';
    case PermanentFailure = 'permanent_failure';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Rejected = 'rejected';
    case Unknown = 'unknown';

    public function mutatesMessageState(): bool
    {
        return match ($this) {
            self::Delivered, self::PermanentFailure, self::Bounced, self::Rejected => true,
            default => false,
        };
    }
}
