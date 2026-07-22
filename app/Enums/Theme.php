<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * User interface theme preferences.
 */
enum Theme: string
{
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::System->value => 'System',
            self::Light->value => 'Light',
            self::Dark->value => 'Dark',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
