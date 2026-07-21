<?php

declare(strict_types=1);

namespace App\DTOs\Attachment;

use App\Enums\AttachmentScanStatus;

/**
 * Immutable input data for partially updating an attachment record.
 */
final readonly class UpdateAttachmentData
{
    /**
     * @param string|null $originalFilename Original uploaded filename.
     * @param string|null $storedFilename Filename stored on disk.
     * @param string|null $mimeType Attachment MIME type.
     * @param string|null $extension Optional file extension.
     * @param int|null $sizeBytes Attachment size in bytes.
     * @param string|null $checksumSha256 SHA-256 checksum of the file content.
     * @param string|null $storageDisk Storage disk name.
     * @param string|null $storagePath Path on the storage disk.
     * @param bool|null $isSafe Optional safety flag.
     * @param AttachmentScanStatus|null $scanStatus Current malware scan status.
     * @param array<string, mixed>|null $metadata Optional additional attachment metadata.
     */
    public function __construct(
        public ?string $originalFilename,
        public ?string $storedFilename,
        public ?string $mimeType,
        public ?string $extension,
        public ?int $sizeBytes,
        public ?string $checksumSha256,
        public ?string $storageDisk,
        public ?string $storagePath,
        public ?bool $isSafe,
        public ?AttachmentScanStatus $scanStatus,
        public ?array $metadata,
    ) {}

    /**
     * Create a DTO instance from an array payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $scanStatus = null;

        if (array_key_exists('scan_status', $data)) {
            $scanStatus = $data['scan_status'] instanceof AttachmentScanStatus
                ? $data['scan_status']
                : AttachmentScanStatus::from((string) $data['scan_status']);
        }

        return new self(
            originalFilename: array_key_exists('original_filename', $data)
                ? ($data['original_filename'] !== null ? (string) $data['original_filename'] : null)
                : null,
            storedFilename: array_key_exists('stored_filename', $data)
                ? ($data['stored_filename'] !== null ? (string) $data['stored_filename'] : null)
                : null,
            mimeType: array_key_exists('mime_type', $data)
                ? ($data['mime_type'] !== null ? (string) $data['mime_type'] : null)
                : null,
            extension: array_key_exists('extension', $data)
                ? ($data['extension'] !== null ? (string) $data['extension'] : null)
                : null,
            sizeBytes: array_key_exists('size_bytes', $data)
                ? ($data['size_bytes'] !== null ? (int) $data['size_bytes'] : null)
                : null,
            checksumSha256: array_key_exists('checksum_sha256', $data)
                ? ($data['checksum_sha256'] !== null ? (string) $data['checksum_sha256'] : null)
                : null,
            storageDisk: array_key_exists('storage_disk', $data)
                ? ($data['storage_disk'] !== null ? (string) $data['storage_disk'] : null)
                : null,
            storagePath: array_key_exists('storage_path', $data)
                ? ($data['storage_path'] !== null ? (string) $data['storage_path'] : null)
                : null,
            isSafe: array_key_exists('is_safe', $data)
                ? ($data['is_safe'] !== null ? (bool) $data['is_safe'] : null)
                : null,
            scanStatus: $scanStatus,
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

        if ($this->originalFilename !== null) {
            $attributes['original_filename'] = $this->originalFilename;
        }

        if ($this->storedFilename !== null) {
            $attributes['stored_filename'] = $this->storedFilename;
        }

        if ($this->mimeType !== null) {
            $attributes['mime_type'] = $this->mimeType;
        }

        if ($this->extension !== null) {
            $attributes['extension'] = $this->extension;
        }

        if ($this->sizeBytes !== null) {
            $attributes['size_bytes'] = $this->sizeBytes;
        }

        if ($this->checksumSha256 !== null) {
            $attributes['checksum_sha256'] = $this->checksumSha256;
        }

        if ($this->storageDisk !== null) {
            $attributes['storage_disk'] = $this->storageDisk;
        }

        if ($this->storagePath !== null) {
            $attributes['storage_path'] = $this->storagePath;
        }

        if ($this->isSafe !== null) {
            $attributes['is_safe'] = $this->isSafe;
        }

        if ($this->scanStatus !== null) {
            $attributes['scan_status'] = $this->scanStatus->value;
        }

        if ($this->metadata !== null) {
            $attributes['metadata'] = $this->metadata;
        }

        return $attributes;
    }
}
