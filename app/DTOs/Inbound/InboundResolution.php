<?php

declare(strict_types=1);

namespace App\DTOs\Inbound;

use App\Enums\InboundRoutingCode;
use App\Models\Domain;
use App\Models\Inbox;

final readonly class InboundResolution
{
    public function __construct(
        public InboundRoutingCode $code,
        public ?string $normalizedAddress = null,
        public ?string $domainId = null,
        public ?string $inboxId = null,
        public ?string $userId = null,
        public bool $isAnonymous = false,
        public bool $retryable = false,
    ) {}

    public static function fromModels(Inbox $inbox, Domain $domain): self
    {
        return new self(InboundRoutingCode::Resolved, $inbox->full_address, (string) $domain->id, (string) $inbox->id, $inbox->user_id, $inbox->user_id === null, false);
    }
}
