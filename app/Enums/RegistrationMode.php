<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Public self-service registration modes. Unknown config values fail closed.
 */
enum RegistrationMode: string
{
    case Disabled = 'disabled';
    case Open = 'open';
    case InviteOnly = 'invite_only';

    /**
     * Resolve from config string; unknown values become Disabled.
     */
    public static function fromConfig(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Disabled;
    }

    public function allowsSelfService(): bool
    {
        return $this !== self::Disabled;
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Disabled->value => 'Disabled',
            self::Open->value => 'Open',
            self::InviteOnly->value => 'Invite only',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
