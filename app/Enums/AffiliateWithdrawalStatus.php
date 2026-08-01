<?php

declare(strict_types=1);

namespace App\Enums;

enum AffiliateWithdrawalStatus: string
{
    case Requested = 'requested';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Processing = 'processing';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Requested->value => 'Requested',
            self::UnderReview->value => 'Under review',
            self::Approved->value => 'Approved',
            self::Rejected->value => 'Rejected',
            self::Processing->value => 'Processing',
            self::Paid->value => 'Paid',
            self::Cancelled->value => 'Cancelled',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
