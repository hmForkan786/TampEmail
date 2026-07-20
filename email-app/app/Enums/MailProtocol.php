<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mail ingestion transport protocols.
 */
enum MailProtocol: string
{
    case Smtp = 'smtp';
    case Lmtp = 'lmtp';
    case Api = 'api';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Smtp->value => 'SMTP',
            self::Lmtp->value => 'LMTP',
            self::Api->value => 'API',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
