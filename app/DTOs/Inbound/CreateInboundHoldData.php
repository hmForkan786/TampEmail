<?php
declare(strict_types=1);
namespace App\DTOs\Inbound;
use Carbon\CarbonInterface;
final readonly class CreateInboundHoldData
{
    public function __construct(public string $targetType, public string $targetId, public string $heldByUserId, public string $reason, public ?CarbonInterface $heldUntil = null) {}
}
