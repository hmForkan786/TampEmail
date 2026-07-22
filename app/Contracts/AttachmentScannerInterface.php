<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\Attachment\AttachmentScanRequest;
use App\DTOs\Attachment\AttachmentScanResultData;

interface AttachmentScannerInterface
{
    public function scan(AttachmentScanRequest $request): AttachmentScanResultData;
}
