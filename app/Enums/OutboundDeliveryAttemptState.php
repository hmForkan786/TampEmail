<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle state of a single outbound delivery attempt row.
 *
 * One row per (outbound_message_id, attempt_number). `Attempted` is the
 * in-flight state between claim and outcome; every other case is terminal
 * for that attempt (a new attempt gets its own row on retry).
 */
enum OutboundDeliveryAttemptState: string
{
    case Attempted = 'attempted';
    case Accepted = 'accepted';
    case TemporaryFailure = 'temporary_failure';
    case PermanentFailure = 'permanent_failure';
    case Ambiguous = 'ambiguous';

    public function isTerminal(): bool
    {
        return $this !== self::Attempted;
    }
}
