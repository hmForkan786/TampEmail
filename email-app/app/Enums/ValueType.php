<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Data type identifiers for system setting values.
 */
enum ValueType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Json = 'json';
    case Array = 'array';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::String->value => 'String',
            self::Integer->value => 'Integer',
            self::Boolean->value => 'Boolean',
            self::Json->value => 'JSON',
            self::Array->value => 'Array',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
