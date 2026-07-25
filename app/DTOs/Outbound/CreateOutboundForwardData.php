<?php

declare(strict_types=1);

namespace App\DTOs\Outbound;

final readonly class CreateOutboundForwardData
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  list<string>  $attachmentIds
     */
    public function __construct(
        public string $emailId,
        public string $idempotencyKey,
        public array $to,
        public array $cc,
        public array $bcc,
        public ?string $textBody,
        public ?string $htmlBody,
        public ?string $subject = null,
        public array $attachmentIds = [],
    ) {}
}
