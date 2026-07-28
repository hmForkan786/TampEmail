<?php

declare(strict_types=1);

namespace App\Enums;

enum ManualCryptoClaimState: string
{
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
