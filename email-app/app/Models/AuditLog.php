<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only security and administrative audit trail entry.
 *
 * @property string $id
 * @property string|null $user_id
 * @property string $action
 * @property string|null $auditable_type
 * @property string|null $auditable_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 *
 * @property-read User|null $user
 */
class AuditLog extends BaseModel
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'audit_logs';

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
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'ip_address',
        'user_agent',
        'old_values',
        'new_values',
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
            'user_id' => 'string',
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
        ]);
    }

    /**
     * Get the user who performed the audited action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to audit logs with the given action.
     */
    #[Scope]
    protected function action(Builder $query, string $action): void
    {
        $query->where('action', $action);
    }

    /**
     * Scope a query to audit logs performed by the given user.
     */
    #[Scope]
    protected function byUser(Builder $query, string $userId): void
    {
        $query->where('user_id', $userId);
    }

    /**
     * Scope a query to the most recent audit logs first.
     */
    #[Scope]
    protected function recent(Builder $query): void
    {
        $query->orderByDesc('created_at');
    }

    /**
     * Determine whether the audit log is attributed to a user.
     */
    public function hasUser(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Determine whether the audit log recorded value changes.
     */
    public function hasChanges(): bool
    {
        return $this->old_values !== null || $this->new_values !== null;
    }

    /**
     * Determine whether the audit log references the given auditable type.
     */
    public function isFor(string $type): bool
    {
        return $this->auditable_type === $type;
    }
}
