<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle states for outbound messages.
 *
 * {@see sent} means the configured transport accepted the message.
 * {@see delivered} requires a trusted provider delivery event and
 * must not be set from SMTP/provider acceptance alone.
 */
enum OutboundMessageState: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Draft->value => 'Draft',
            self::Queued->value => 'Queued',
            self::Sending->value => 'Sending',
            self::Sent->value => 'Sent',
            self::Delivered->value => 'Delivered',
            self::Failed->value => 'Failed',
            self::Cancelled->value => 'Cancelled',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /**
     * Terminal for the send/retry pipeline. Sent may still receive delivery events.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled], true)
            || ($this === self::Failed);
    }

    /**
     * Whether a stale delivery job must refuse to mutate this state.
     */
    public function blocksStaleJobMutation(): bool
    {
        return in_array($this, [self::Sent, self::Delivered, self::Cancelled, self::Failed], true);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Queued],
            self::Queued => [self::Sending, self::Cancelled],
            self::Sending => [self::Sent, self::Failed, self::Queued],
            self::Sent => [self::Delivered, self::Failed],
            self::Failed => [self::Queued],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Reconciliation precedence, most authoritative first. Lower rank wins
     * and must never be silently overwritten by a higher-rank (less final)
     * state or event:
     *
     * cancelled > delivered > failed (permanent_failure) > sent >
     * sending (in-flight / temporary_failure territory) > queued > draft.
     *
     * `delivered` outranks `failed`: a permanent-failure provider event
     * arriving after delivery is ignored. `sent` never outranks `delivered`
     * or `cancelled`. A temporary failure (which never mutates state, see
     * {@see OutboundProviderEventType::mutatesMessageState()}) can never
     * outrank anything because it has no message-state rank of its own —
     * it only ever results in a `queued` retry or a terminal `failed`
     * once attempts are exhausted.
     */
    public function precedenceRank(): int
    {
        return match ($this) {
            self::Cancelled => 0,
            self::Delivered => 1,
            self::Failed => 2,
            self::Sent => 3,
            self::Sending => 4,
            self::Queued => 5,
            self::Draft => 6,
        };
    }

    /**
     * True when this state is at least as authoritative as $other and must
     * not be downgraded/overwritten by a mutation intended for $other.
     */
    public function outranksOrEquals(self $other): bool
    {
        return $this->precedenceRank() <= $other->precedenceRank();
    }
}
