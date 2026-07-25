<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Provider-independent transport submission outcomes.
 */
enum OutboundTransportResult: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case TemporaryFailure = 'temporary_failure';
    case PermanentFailure = 'permanent_failure';
    case ConfigurationFailure = 'configuration_failure';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Accepted->value => 'Accepted',
            self::Rejected->value => 'Rejected',
            self::TemporaryFailure->value => 'Temporary failure',
            self::PermanentFailure->value => 'Permanent failure',
            self::ConfigurationFailure->value => 'Configuration failure',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    public function isSuccess(): bool
    {
        return $this === self::Accepted;
    }

    public function isRetryable(): bool
    {
        return $this === self::TemporaryFailure;
    }

    /**
     * Map a transport outcome onto the outbound message lifecycle target state
     * when no further retry will be scheduled.
     */
    public function toMessageState(bool $scheduleRetry = false): OutboundMessageState
    {
        return match ($this) {
            self::Accepted => OutboundMessageState::Sent,
            self::TemporaryFailure => $scheduleRetry
                ? OutboundMessageState::Queued
                : OutboundMessageState::Failed,
            self::Rejected, self::PermanentFailure, self::ConfigurationFailure => OutboundMessageState::Failed,
        };
    }
}
