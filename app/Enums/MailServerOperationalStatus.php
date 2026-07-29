<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Application-side mail server pool operational states.
 *
 * These govern inventory eligibility for new inbox assignment only.
 * They do not control an MTA, SMTP listener, or MX exchanger.
 */
enum MailServerOperationalStatus: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Draining = 'draining';
    case Disabled = 'disabled';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Active->value => 'Active',
            self::Maintenance->value => 'Maintenance',
            self::Draining->value => 'Draining',
            self::Disabled->value => 'Disabled',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /** Whether new inbox assignments may target this server. */
    public function acceptsNewAssignments(): bool
    {
        return $this === self::Active;
    }

    /** Whether existing assigned inboxes may remain on this server. */
    public function retainsExistingAssignments(): bool
    {
        return $this !== self::Disabled;
    }
}
