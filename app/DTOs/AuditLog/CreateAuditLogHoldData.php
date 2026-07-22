<?php
declare(strict_types=1);
namespace App\DTOs\AuditLog;
use Carbon\CarbonInterface;
final readonly class CreateAuditLogHoldData
{
    public function __construct(public string $auditLogId, public string $heldByUserId, public string $reason, public ?CarbonInterface $heldUntil = null) {}
}
