<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Classification of disposable inbox access models.
 */
enum InboxType: string
{
    case Temporary = 'temporary';
    case Private = 'private';
    case Reserved = 'reserved';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Temporary->value => 'Temporary',
            self::Private->value => 'Private',
            self::Reserved->value => 'Reserved',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
