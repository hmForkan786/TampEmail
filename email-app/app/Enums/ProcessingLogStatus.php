<?php

declare(strict_types=1);

namespace App\Enums;

enum ProcessingLogStatus: string
{
    case Started = 'started';
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Retrying = 'retrying';
}
