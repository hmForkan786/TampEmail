<?php

declare(strict_types=1);

namespace App\Enums;

enum ManualCryptoEvidenceStatus: string
{
    case Submitted = 'submitted';
    case ManuallyVerified = 'manually_verified';
    case Rejected = 'rejected';
}
