<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Platform authorization role for operator and admin capabilities.
 */
enum PlatformRole: string
{
    case User = 'user';
    case Operator = 'operator';
    case Admin = 'admin';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::User->value => 'User',
            self::Operator->value => 'Operator',
            self::Admin->value => 'Admin',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /**
     * Whether this role may hold any privileged platform capability.
     */
    public function isPrivileged(): bool
    {
        return $this === self::Operator || $this === self::Admin;
    }
}
