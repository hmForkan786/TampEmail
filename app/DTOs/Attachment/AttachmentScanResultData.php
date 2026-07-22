<?php

declare(strict_types=1);

namespace App\DTOs\Attachment;

use App\Enums\AttachmentScanResult;

final readonly class AttachmentScanResultData
{
    public function __construct(public AttachmentScanResult $result, public ?string $signature = null, public ?string $scannerVersion = null) {}
}
