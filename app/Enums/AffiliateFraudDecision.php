<?php

declare(strict_types=1);

namespace App\Enums;

enum AffiliateFraudDecision: string
{
    case Allow = 'allow';
    case ManualReview = 'manual_review';
    case Reject = 'reject';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Allow->value => 'Allow',
            self::ManualReview->value => 'Manual review',
            self::Reject->value => 'Reject',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
