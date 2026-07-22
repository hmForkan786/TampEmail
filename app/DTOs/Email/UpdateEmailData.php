<?php

declare(strict_types=1);

namespace App\DTOs\Email;

use App\Enums\ProcessingStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Immutable input data for partially updating an email record.
 */
final readonly class UpdateEmailData
{
    /**
     * @param string|null $senderName Optional sender display name.
     * @param string|null $senderEmail Sender email address.
     * @param string|null $recipientEmail Recipient email address.
     * @param string|null $subject Optional email subject line.
     * @param CarbonInterface|null $receivedAt Timestamp when the email was received.
     * @param bool|null $hasHtml Whether an HTML body exists.
     * @param bool|null $hasText Whether a plain-text body exists.
     * @param bool|null $hasAttachments Whether attachments exist.
     * @param int|null $attachmentCount Total number of attachments.
     * @param int|null $sizeBytes Raw message size in bytes.
     * @param ProcessingStatus|null $processingStatus Current processing pipeline state.
     * @param string|null $spamScore Optional spam score value.
     * @param array<string, mixed>|null $headers Optional raw message headers.
     * @param array<string, mixed>|null $metadata Optional additional email metadata.
     */
    public function __construct(
        public ?string $senderName,
        public ?string $senderEmail,
        public ?string $recipientEmail,
        public ?string $subject,
        public ?CarbonInterface $receivedAt,
        public ?bool $hasHtml,
        public ?bool $hasText,
        public ?bool $hasAttachments,
        public ?int $attachmentCount,
        public ?int $sizeBytes,
        public ?ProcessingStatus $processingStatus,
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
        $processingStatus = null;

        if (array_key_exists('processing_status', $data)) {
            $processingStatus = $data['processing_status'] instanceof ProcessingStatus
                ? $data['processing_status']
                : ProcessingStatus::from((string) $data['processing_status']);
        }

        $receivedAt = null;

        if (array_key_exists('received_at', $data)) {
            $receivedAt = $data['received_at'] === null
                ? null
                : ($data['received_at'] instanceof CarbonInterface
                    ? $data['received_at']
                    : Carbon::parse($data['received_at']));
        }

        return new self(
            senderName: array_key_exists('sender_name', $data)
                ? ($data['sender_name'] !== null ? (string) $data['sender_name'] : null)
                : null,
            senderEmail: array_key_exists('sender_email', $data)
                ? ($data['sender_email'] !== null ? (string) $data['sender_email'] : null)
                : null,
            recipientEmail: array_key_exists('recipient_email', $data)
                ? ($data['recipient_email'] !== null ? (string) $data['recipient_email'] : null)
                : null,
            subject: array_key_exists('subject', $data)
                ? ($data['subject'] !== null ? (string) $data['subject'] : null)
                : null,
            receivedAt: $receivedAt,
            hasHtml: array_key_exists('has_html', $data)
                ? ($data['has_html'] !== null ? (bool) $data['has_html'] : null)
                : null,
            hasText: array_key_exists('has_text', $data)
                ? ($data['has_text'] !== null ? (bool) $data['has_text'] : null)
                : null,
            hasAttachments: array_key_exists('has_attachments', $data)
                ? ($data['has_attachments'] !== null ? (bool) $data['has_attachments'] : null)
                : null,
            attachmentCount: array_key_exists('attachment_count', $data)
                ? ($data['attachment_count'] !== null ? (int) $data['attachment_count'] : null)
                : null,
            sizeBytes: array_key_exists('size_bytes', $data)
                ? ($data['size_bytes'] !== null ? (int) $data['size_bytes'] : null)
                : null,
            processingStatus: $processingStatus,
            spamScore: array_key_exists('spam_score', $data)
                ? ($data['spam_score'] !== null ? (string) $data['spam_score'] : null)
                : null,
            headers: array_key_exists('headers', $data)
                ? ($data['headers'] !== null ? (array) $data['headers'] : null)
                : null,
            metadata: array_key_exists('metadata', $data)
                ? ($data['metadata'] !== null ? (array) $data['metadata'] : null)
                : null,
        );
    }

    /**
     * Convert the DTO to model-fillable attributes for update.
     *
     * Only non-null properties are included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $attributes = [];

        if ($this->senderName !== null) {
            $attributes['sender_name'] = $this->senderName;
        }

        if ($this->senderEmail !== null) {
            $attributes['sender_email'] = $this->senderEmail;
        }

        if ($this->recipientEmail !== null) {
            $attributes['recipient_email'] = $this->recipientEmail;
        }

        if ($this->subject !== null) {
            $attributes['subject'] = $this->subject;
        }

        if ($this->receivedAt !== null) {
            $attributes['received_at'] = $this->receivedAt;
        }

        if ($this->hasHtml !== null) {
            $attributes['has_html'] = $this->hasHtml;
        }

        if ($this->hasText !== null) {
            $attributes['has_text'] = $this->hasText;
        }

        if ($this->hasAttachments !== null) {
            $attributes['has_attachments'] = $this->hasAttachments;
        }

        if ($this->attachmentCount !== null) {
            $attributes['attachment_count'] = $this->attachmentCount;
        }

        if ($this->sizeBytes !== null) {
            $attributes['size_bytes'] = $this->sizeBytes;
        }

        if ($this->processingStatus !== null) {
            $attributes['processing_status'] = $this->processingStatus->value;
        }

        if ($this->spamScore !== null) {
            $attributes['spam_score'] = $this->spamScore;
        }

        if ($this->headers !== null) {
            $attributes['headers'] = $this->headers;
        }

        if ($this->metadata !== null) {
            $attributes['metadata'] = $this->metadata;
        }

        return $attributes;
    }
}
