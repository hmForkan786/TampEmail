<?php

declare(strict_types=1);

namespace App\DTOs\Attachment;

use App\Enums\AttachmentScanStatus;

/**
 * Immutable input data for creating a new attachment record.
 */
final readonly class CreateAttachmentData
{
    /**
     * @param string $emailId Parent email UUID.
     * @param string $originalFilename Original uploaded filename.
     * @param string $storedFilename Filename stored on disk.
     * @param string $mimeType Attachment MIME type.
     * @param string|null $extension Optional file extension.
     * @param int $sizeBytes Attachment size in bytes.
     * @param string $checksumSha256 SHA-256 checksum of the file content.
     * @param string $storageDisk Storage disk name.
     * @param string $storagePath Path on the storage disk.
     * @param bool|null $isSafe Optional safety flag.
     * @param AttachmentScanStatus $scanStatus Current malware scan status.
     * @param array<string, mixed>|null $metadata Optional additional attachment metadata.
     */
    public function __construct(
        public string $emailId,
        public string $originalFilename,
        public string $storedFilename,
        public string $mimeType,
        public ?string $extension,
        public int $sizeBytes,
        public string $checksumSha256,
        public string $storageDisk,
        public string $storagePath,
        public ?bool $isSafe,
        public AttachmentScanStatus $scanStatus,
        public ?array $metadata,
    ) {}

    /**
     * Create a DTO instance from an array payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $scanStatus = $data['scan_status'] ?? AttachmentScanStatus::Pending;

        if (! $scanStatus instanceof AttachmentScanStatus) {
            $scanStatus = AttachmentScanStatus::from((string) $scanStatus);
        }

        return new self(
            emailId: (string) $data['email_id'],
            originalFilename: (string) $data['original_filename'],
            storedFilename: (string) $data['stored_filename'],
            mimeType: (string) $data['mime_type'],
            extension: isset($data['extension']) ? (string) $data['extension'] : null,
            sizeBytes: (int) $data['size_bytes'],
            checksumSha256: (string) $data['checksum_sha256'],
            storageDisk: (string) $data['storage_disk'],
            storagePath: (string) $data['storage_path'],
            isSafe: array_key_exists('is_safe', $data)
                ? ($data['is_safe'] !== null ? (bool) $data['is_safe'] : null)
                : null,
            scanStatus: $scanStatus,
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
            'email_id' => $this->emailId,
            'original_filename' => $this->originalFilename,
            'stored_filename' => $this->storedFilename,
            'mime_type' => $this->mimeType,
            'extension' => $this->extension,
            'size_bytes' => $this->sizeBytes,
            'checksum_sha256' => $this->checksumSha256,
            'storage_disk' => $this->storageDisk,
            'storage_path' => $this->storagePath,
            'is_safe' => $this->isSafe,
            'scan_status' => $this->scanStatus->value,
            'metadata' => $this->metadata,
        ];
    }
}
