<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle state of an outbound usage reservation.
 *
 * `reserved` consumes allowance immediately (checked as
 * `used_value + outstanding reservations`) but does not yet increment the
 * subscription usage counter. `committed` moves the reserved units into the
 * subscription usage counter (provider accepted / message sent) and is
 * terminal. `released` returns the reservation's units to the allowance
 * without ever touching the usage counter (pre-transport-attempt failure or
 * cancellation) and is terminal. `expired` is a reconciliation-only terminal
 * state for abandoned reservations past their TTL that qualify for release
 * per policy (see docs/OUTBOUND_USAGE_ACCOUNTING.md).
 */
enum OutboundUsageReservationState: string
{
    case Reserved = 'reserved';
    case Committed = 'committed';
    case Released = 'released';
    case Expired = 'expired';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Reserved->value => 'Reserved',
            self::Committed->value => 'Committed',
            self::Released->value => 'Released',
            self::Expired->value => 'Expired',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    public function isTerminal(): bool
    {
        return $this !== self::Reserved;
    }
}
