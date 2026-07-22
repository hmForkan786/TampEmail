<?php

declare(strict_types=1);

namespace App\Enums;

enum ProcessingStage: string
{
    case Receive = 'receive';
    case Parse = 'parse';
    case Scan = 'scan';
    case StoreBody = 'store_body';
    case StoreAttachments = 'store_attachments';
    case Notify = 'notify';
    case Cleanup = 'cleanup';
}
