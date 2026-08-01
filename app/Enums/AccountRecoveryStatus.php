<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountRecoveryStatus: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Submitted->value => 'Submitted',
            self::UnderReview->value => 'Under review',
            self::Approved->value => 'Approved',
            self::Rejected->value => 'Rejected',
            self::Completed->value => 'Completed',
            self::Cancelled->value => 'Cancelled',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Rejected, self::Completed, self::Cancelled], true);
    }
}
