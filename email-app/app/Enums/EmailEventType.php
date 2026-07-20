<?php

declare(strict_types=1);

namespace App\Enums;

enum EmailEventType: string
{
    case Received = 'received';
    case Parsed = 'parsed';
    case Stored = 'stored';
    case Viewed = 'viewed';
    case Downloaded = 'downloaded';
    case Deleted = 'deleted';
    case Expired = 'expired';
    case Failed = 'failed';
}
