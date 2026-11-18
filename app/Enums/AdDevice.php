<?php

declare(strict_types=1);

namespace App\Enums;

enum AdDevice: string
{
    case Desktop = 'desktop';
    case Mobile = 'mobile';
    case Tablet = 'tablet';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Desktop->value => 'Desktop',
            self::Mobile->value => 'Mobile',
            self::Tablet->value => 'Tablet',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
