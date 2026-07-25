<?php

declare(strict_types=1);

namespace App\DTOs\Outbound;

/**
 * Provider-independent outbound message payload for transport submission.
 *
 * Does not carry credentials, storage paths, or queue metadata.
 */
final readonly class OutboundMessageData
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  list<array{filename: string, mime_type: string, size_bytes: int, storage_disk: string, storage_path: string}>  $attachments
     */
    public function __construct(
        public string $messageId,
        public string $fromAddress,
        public ?string $fromDisplayName,
        public array $to,
        public array $cc,
        public array $bcc,
        public string $subject,
        public ?string $textBody,
        public ?string $htmlBody,
        public ?string $inReplyTo = null,
        public ?string $references = null,
        public array $attachments = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'from_address' => $this->fromAddress,
            'from_display_name' => $this->fromDisplayName,
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'subject' => $this->subject,
            'text_body' => $this->textBody,
            'html_body' => $this->htmlBody,
            'in_reply_to' => $this->inReplyTo,
            'references' => $this->references,
            'attachments' => $this->attachments,
        ];
    }
}
