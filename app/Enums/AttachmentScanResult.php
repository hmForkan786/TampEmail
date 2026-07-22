<?php

declare(strict_types=1);

namespace App\Enums;

enum AttachmentScanResult: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';
}
