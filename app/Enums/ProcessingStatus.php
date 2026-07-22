<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Inbound email processing pipeline states.
 */
enum ProcessingStatus: string
{
    case Received = 'received';
    case Parsing = 'parsing';
    case Parsed = 'parsed';
    case Stored = 'stored';
    case Failed = 'failed';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Received->value => 'Received',
            self::Parsing->value => 'Parsing',
            self::Parsed->value => 'Parsed',
            self::Stored->value => 'Stored',
            self::Failed->value => 'Failed',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
