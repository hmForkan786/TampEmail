<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

final readonly class CallbackIngestionResult
{
    public function __construct(
        public bool $accepted,
        public bool $duplicate,
        public string $providerEventId,
        public string $internalEventId,
        public string $processingStatus,
        public string $acknowledgementCode,
    ) {}
}
