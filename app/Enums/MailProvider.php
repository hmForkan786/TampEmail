<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Inbound mail server provider identifiers.
 */
enum MailProvider: string
{
    case Postfix = 'postfix';
    case Mailgun = 'mailgun';
    case Ses = 'ses';
    case Smtp = 'smtp';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Postfix->value => 'Postfix',
            self::Mailgun->value => 'Mailgun',
            self::Ses->value => 'Amazon SES',
            self::Smtp->value => 'SMTP',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
