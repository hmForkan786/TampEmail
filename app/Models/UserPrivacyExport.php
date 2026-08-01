<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrivacyExportStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bounded personal data export request foundation.
 *
 * @property string $id
 * @property string $user_id
 * @property PrivacyExportStatus $status
 * @property string|null $disk
 * @property string|null $path
 * @property array<int, string>|null $included_datasets
 * @property array<int, string>|null $deferred_datasets
 * @property string|null $failure_reason
 * @property Carbon|null $requested_at
 * @property Carbon|null $ready_at
 * @property Carbon|null $downloaded_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserPrivacyExport extends Model
{
    use HasUuid;

    protected $table = 'user_privacy_exports';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'status',
        'disk',
        'path',
        'included_datasets',
        'deferred_datasets',
        'failure_reason',
        'requested_at',
        'ready_at',
        'downloaded_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PrivacyExportStatus::class,
            'included_datasets' => 'array',
            'deferred_datasets' => 'array',
            'requested_at' => 'datetime',
            'ready_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDownloadable(): bool
    {
        if ($this->status !== PrivacyExportStatus::Ready && $this->status !== PrivacyExportStatus::Downloaded) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return is_string($this->path) && $this->path !== '';
    }
}
