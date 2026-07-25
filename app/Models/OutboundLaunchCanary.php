<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OutboundCanarySubjectType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Admin-identified outbound launch canary subject (user, inbox, domain, or
 * API key). Canary membership only affects the rollout-mode eligibility
 * check — it never bypasses domain verification, suppression, quotas, or
 * worker readiness.
 *
 * @property string $id
 * @property OutboundCanarySubjectType $subject_type
 * @property string $subject_id
 * @property string|null $label
 * @property bool $active
 * @property string|null $added_by
 * @property Carbon $added_at
 * @property string|null $removed_by
 * @property Carbon|null $removed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OutboundLaunchCanary extends BaseModel
{
    protected $table = 'outbound_launch_canaries';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subject_type',
        'subject_id',
        'label',
        'active',
        'added_by',
        'added_at',
        'removed_by',
        'removed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'subject_type' => OutboundCanarySubjectType::class,
            'active' => 'boolean',
            'added_at' => 'datetime',
            'removed_at' => 'datetime',
        ]);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('active', true);
    }
}
