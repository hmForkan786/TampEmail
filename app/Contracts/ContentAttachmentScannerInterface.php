<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\Attachment\AttachmentScanResultData;

interface ContentAttachmentScannerInterface
{
    public function scanContent(string $content, ?string $filename = null): AttachmentScanResultData;
}
