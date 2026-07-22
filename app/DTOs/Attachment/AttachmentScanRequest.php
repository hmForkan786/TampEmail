<?php

declare(strict_types=1);

namespace App\DTOs\Attachment;

final readonly class AttachmentScanRequest
{
    public function __construct(public string $storageDisk, public string $storagePath, public int $sizeBytes, public string $checksumSha256, public string $mimeType) {}
}
