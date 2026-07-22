<?php
declare(strict_types=1);
namespace App\Services\Inbound;
use App\Contracts\AttachmentScannerInterface;
use App\DTOs\Attachment\AttachmentScanRequest;
use App\DTOs\Attachment\AttachmentScanResultData;
use App\Enums\AttachmentScanResult;
final class DisabledAttachmentScanner implements AttachmentScannerInterface
{
    public function scan(AttachmentScanRequest $request): AttachmentScanResultData { return new AttachmentScanResultData(AttachmentScanResult::Failed); }
}
