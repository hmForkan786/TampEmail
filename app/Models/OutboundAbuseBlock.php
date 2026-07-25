<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Temporary outbound abuse block or suspension marker.
 *
 * @property string $id
 * @property string $user_id
 * @property string $state
 * @property string $reason_code
 * @property string $source
 * @property string|null $actor_user_id
 * @property Carbon $started_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $cleared_at
 * @property array<string, mixed>|null $metadata
 */
class OutboundAbuseBlock extends BaseModel
{
    protected $table = 'outbound_abuse_blocks';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'state',
        'reason_code',
        'source',
        'actor_user_id',
        'started_at',
        'expires_at',
        'cleared_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'cleared_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        if ($this->cleared_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return in_array($this->state, ['temporarily_blocked', 'suspended'], true);
    }
}
