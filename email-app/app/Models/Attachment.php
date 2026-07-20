<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttachmentScanStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Email attachment metadata; file content is stored on configured disks.
 *
 * @property string $id
 * @property string $email_id
 * @property string $original_filename
 * @property string $stored_filename
 * @property string $mime_type
 * @property string|null $extension
 * @property int $size_bytes
 * @property string $checksum_sha256
 * @property string $storage_disk
 * @property string $storage_path
 * @property bool|null $is_safe
 * @property AttachmentScanStatus $scan_status
 * @property Carbon|null $scanned_at
 * @property int $downloaded_count
 * @property Carbon|null $last_downloaded_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Email $email
 */
class Attachment extends BaseModel
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'attachments';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email_id',
        'original_filename',
        'stored_filename',
        'mime_type',
        'extension',
        'size_bytes',
        'checksum_sha256',
        'storage_disk',
        'storage_path',
        'is_safe',
        'scan_status',
        'scanned_at',
        'downloaded_count',
        'last_downloaded_at',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_safe' => 'boolean',
            'scan_status' => AttachmentScanStatus::class,
            'scanned_at' => 'datetime',
            'downloaded_count' => 'integer',
            'last_downloaded_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    /**
     * Get the email that owns the attachment.
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /**
     * Scope a query to attachments marked as safe.
     */
    #[Scope]
    protected function safe(Builder $query): void
    {
        $query->where('is_safe', true);
    }

    /**
     * Scope a query to attachments marked as unsafe.
     */
    #[Scope]
    protected function unsafe(Builder $query): void
    {
        $query->where('is_safe', false);
    }

    /**
     * Scope a query to attachments awaiting scan.
     */
    #[Scope]
    protected function pendingScan(Builder $query): void
    {
        $query->where('scan_status', AttachmentScanStatus::Pending);
    }

    /**
     * Determine whether the attachment is marked safe.
     */
    public function isSafe(): bool
    {
        return $this->is_safe === true;
    }

    /**
     * Determine whether the attachment scan has completed.
     */
    public function isScanned(): bool
    {
        return $this->scanned_at !== null;
    }

    /**
     * Determine whether the attachment has been downloaded at least once.
     */
    public function hasBeenDownloaded(): bool
    {
        return $this->downloaded_count > 0;
    }
}
