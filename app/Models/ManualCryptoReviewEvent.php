<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property string $claim_id
 * @property string|null $actor_id
 * @property string $event
 * @property string|null $from_state
 * @property string $to_state
 * @property string|null $reason
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 */
final class ManualCryptoReviewEvent extends BaseModel
{
    public const UPDATED_AT = null;

    protected $fillable = ['claim_id', 'actor_id', 'event', 'from_state', 'to_state', 'reason', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['metadata' => 'array']);
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Manual crypto review history is append-only.'));
        self::deleting(fn () => throw new LogicException('Manual crypto review history is append-only.'));
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(ManualCryptoPaymentClaim::class, 'claim_id');
    }
}
