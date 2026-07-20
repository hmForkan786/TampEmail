<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only pipeline processing log entry for an inbound email.
 *
 * @property string $id
 * @property string $email_id
 * @property ProcessingStage $stage
 * @property ProcessingLogStatus $status
 * @property string|null $worker
 * @property int|null $duration_ms
 * @property string|null $error_message
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property-read Email $email
 */
class EmailProcessingLog extends BaseModel
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'email_processing_logs';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email_id',
        'stage',
        'status',
        'worker',
        'duration_ms',
        'error_message',
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
            'stage' => ProcessingStage::class,
            'status' => ProcessingLogStatus::class,
            'duration_ms' => 'integer',
            'metadata' => 'array',
        ]);
    }

    /**
     * Get the email associated with the processing log.
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /**
     * Scope a query to logs for the given pipeline stage.
     */
    #[Scope]
    protected function stage(Builder $query, ProcessingStage $stage): void
    {
        $query->where('stage', $stage);
    }

    /**
     * Scope a query to logs with the given status.
     */
    #[Scope]
    protected function status(Builder $query, ProcessingLogStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Scope a query to failed processing logs.
     */
    #[Scope]
    protected function failed(Builder $query): void
    {
        $query->where('status', ProcessingLogStatus::Failed);
    }

    /**
     * Scope a query to the most recent logs first.
     */
    #[Scope]
    protected function recent(Builder $query): void
    {
        $query->orderByDesc('created_at');
    }

    /**
     * Determine whether the processing step completed successfully.
     */
    public function isSuccessful(): bool
    {
        return $this->status === ProcessingLogStatus::Success;
    }

    /**
     * Determine whether the log includes an error message.
     */
    public function hasError(): bool
    {
        return $this->error_message !== null && $this->error_message !== '';
    }

    /**
     * Determine whether processing exceeded the given duration threshold.
     */
    public function tookLongerThan(int $milliseconds): bool
    {
        return $this->duration_ms !== null && $this->duration_ms > $milliseconds;
    }
}
