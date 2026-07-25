<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outbound email operation types.
 *
 * Reply and forward are not aliases of send; each has distinct eligibility,
 * recipient, threading, and attachment rules.
 */
enum OutboundOperation: string
{
    case Send = 'send';
    case Reply = 'reply';
    case Forward = 'forward';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Send->value => 'Send',
            self::Reply->value => 'Reply',
            self::Forward->value => 'Forward',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /**
     * Plan entitlement feature key for this operation.
     */
    public function featureKey(): string
    {
        return match ($this) {
            self::Send => 'send_email',
            self::Reply => 'reply_email',
            self::Forward => 'forward_email',
        };
    }

    public function requiresSourceEmail(): bool
    {
        return $this === self::Reply || $this === self::Forward;
    }
}
