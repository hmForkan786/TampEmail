<?php

declare(strict_types=1);

namespace App\DTOs\Outbound;

final readonly class CreateOutboundSendData
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     */
    public function __construct(
        public string $inboxId,
        public string $idempotencyKey,
        public array $to,
        public array $cc,
        public array $bcc,
        public string $subject,
        public ?string $textBody,
        public ?string $htmlBody,
        public ?string $fromDisplayName = null,
        public ?string $senderProfileId = null,
    ) {}
}
