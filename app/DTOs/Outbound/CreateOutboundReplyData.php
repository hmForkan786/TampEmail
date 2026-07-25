<?php

declare(strict_types=1);

namespace App\DTOs\Outbound;

final readonly class CreateOutboundReplyData
{
    /**
     * @param  list<string>  $cc
     */
    public function __construct(
        public string $emailId,
        public string $idempotencyKey,
        public ?string $textBody,
        public ?string $htmlBody,
        public ?string $subject = null,
        public array $cc = [],
        public bool $includeQuote = true,
    ) {}
}
