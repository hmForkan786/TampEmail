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
}
