<?php

declare(strict_types=1);

namespace App\Enums;

enum EventSource: string
{
    case System = 'system';
    case Web = 'web';
    case Api = 'api';
    case Ingestion = 'ingestion';
    case Worker = 'worker';
}
