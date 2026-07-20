<?php

declare(strict_types=1);

namespace App\DTOs\Email;

use App\Enums\ProcessingStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Immutable input data for creating a new email record.
 */
final readonly class CreateEmailData
{
    /**
     * @param string $inboxId Parent inbox UUID.
     * @param string $messageId Unique external message identifier.
     * @param string|null $senderName Optional sender display name.
     * @param string $senderEmail Sender email address.
     * @param string $recipientEmail Recipient email address.
     * @param string|null $subject Optional email subject line.
     * @param CarbonInterface $receivedAt Timestamp when the email was received.
     * @param bool $hasHtml Whether an HTML body exists.
     * @param bool $hasText Whether a plain-text body exists.
     * @param bool $hasAttachments Whether attachments exist.
     * @param int $attachmentCount Total number of attachments.
     * @param int $sizeBytes Raw message size in bytes.
     * @param ProcessingStatus $processingStatus Current processing pipeline state.
     * @param string|null $spamScore Optional spam score value.
     * @param array<string, mixed>|null $headers Optional raw message headers.
     * @param array<string, mixed>|null $metadata Optional additional email metadata.
     */
    public function __construct(
        public string $inboxId,
        public string $messageId,
        public ?string $senderName,
        public string $senderEmail,
        public string $recipientEmail,
        public ?string $subject,
        public CarbonInterface $receivedAt,
        public bool $hasHtml,
        public bool $hasText,
        public bool $hasAttachments,
        public int $attachmentCount,
        public int $sizeBytes,
        public ProcessingStatus $processingStatus,
        public ?string $spamScore,
        public ?array $headers,
        public ?array $metadata,
    ) {}

    /**
     * Create a DTO instance from an array payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $processingStatus = $data['processing_status'] ?? ProcessingStatus::Received;

        if (! $processingStatus instanceof ProcessingStatus) {
            $processingStatus = ProcessingStatus::from((string) $processingStatus);
        }

        $receivedAt = $data['received_at'];

        if (! $receivedAt instanceof CarbonInterface) {
            $receivedAt = Carbon::parse($receivedAt);
        }

        return new self(
            inboxId: (string) $data['inbox_id'],
            messageId: (string) $data['message_id'],
            senderName: isset($data['sender_name']) ? (string) $data['sender_name'] : null,
            senderEmail: (string) $data['sender_email'],
            recipientEmail: (string) $data['recipient_email'],
            subject: isset($data['subject']) ? (string) $data['subject'] : null,
            receivedAt: $receivedAt,
            hasHtml: (bool) $data['has_html'],
            hasText: (bool) $data['has_text'],
            hasAttachments: (bool) $data['has_attachments'],
            attachmentCount: (int) $data['attachment_count'],
            sizeBytes: (int) $data['size_bytes'],
            processingStatus: $processingStatus,
            spamScore: isset($data['spam_score']) ? (string) $data['spam_score'] : null,
            headers: isset($data['headers']) ? (array) $data['headers'] : null,
            metadata: isset($data['metadata']) ? (array) $data['metadata'] : null,
        );
    }

    /**
     * Convert the DTO to model-fillable attributes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'inbox_id' => $this->inboxId,
            'message_id' => $this->messageId,
            'sender_name' => $this->senderName,
            'sender_email' => $this->senderEmail,
            'recipient_email' => $this->recipientEmail,
            'subject' => $this->subject,
            'received_at' => $this->receivedAt,
            'has_html' => $this->hasHtml,
            'has_text' => $this->hasText,
            'has_attachments' => $this->hasAttachments,
            'attachment_count' => $this->attachmentCount,
            'size_bytes' => $this->sizeBytes,
            'processing_status' => $this->processingStatus->value,
            'spam_score' => $this->spamScore,
            'headers' => $this->headers,
            'metadata' => $this->metadata,
        ];
    }
}
