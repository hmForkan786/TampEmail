<?php

declare(strict_types=1);

namespace App\DTOs\Outbound;

use Carbon\CarbonInterface;

final readonly class CreateOutboundRetentionHoldData
{
    public function __construct(
        public string $messageId,
        public string $heldByUserId,
        public string $reasonCode,
        public ?CarbonInterface $heldUntil = null,
    ) {}
}
