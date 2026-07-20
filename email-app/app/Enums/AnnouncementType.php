<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Visual severity types for global announcements.
 */
enum AnnouncementType: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
    case Maintenance = 'maintenance';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Info->value => 'Info',
            self::Success->value => 'Success',
            self::Warning->value => 'Warning',
            self::Danger->value => 'Danger',
            self::Maintenance->value => 'Maintenance',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
