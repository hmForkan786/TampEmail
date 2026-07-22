<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical API-key permission scopes.
 *
 * This allowlist is the single source of truth for known scopes and the
 * minimum owner platform role required to be issued each scope.
 *
 * Legacy note: existing `api_keys.permissions` rows are not rewritten by this
 * registry. Unknown legacy values may still exist in the database and continue
 * to be trusted by authentication middleware until a dedicated cleanup/gate
 * prompt enforces normalization at issuance and runtime.
 */
enum ApiKeyScope: string
{
    case MailServersRead = 'mail_servers:read';
    case MailServersWrite = 'mail_servers:write';
    case MailServersAdmin = 'mail_servers:admin';
    case InboxesRead = 'inboxes:read';
    case InboxesWrite = 'inboxes:write';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::MailServersRead->value => 'Mail servers read',
            self::MailServersWrite->value => 'Mail servers write',
            self::MailServersAdmin->value => 'Mail servers admin',
            self::InboxesRead->value => 'Inboxes read',
            self::InboxesWrite->value => 'Inboxes write',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /**
     * Minimum owner platform role required to hold this scope.
     *
     * Operator-capable owners satisfy Operator-minimum scopes. Admin-only
     * scopes require a verified platform admin. User-minimum scopes may be
     * held by ordinary end-user owners.
     */
    public function requiredCapability(): PlatformRole
    {
        return match ($this) {
            self::MailServersRead, self::MailServersWrite => PlatformRole::Operator,
            self::MailServersAdmin => PlatformRole::Admin,
            self::InboxesRead, self::InboxesWrite => PlatformRole::User,
        };
    }

    public function requiresAdmin(): bool
    {
        return $this->requiredCapability() === PlatformRole::Admin;
    }

    public function requiresOperator(): bool
    {
        return $this->requiredCapability() === PlatformRole::Operator
            || $this->requiredCapability() === PlatformRole::Admin;
    }
}
