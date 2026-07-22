<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Malware scan lifecycle states for email attachments.
 */
enum AttachmentScanStatus: string
{
    case Pending = 'pending';
    case Scanning = 'scanning';
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Pending->value => 'Pending',
            self::Scanning->value => 'Scanning',
            self::Clean->value => 'Clean',
            self::Infected->value => 'Infected',
            self::Failed->value => 'Failed',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
