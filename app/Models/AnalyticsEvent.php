<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnalyticsDomain;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only sanitized analytics event (no PII payloads).
 *
 * @property string $id
 * @property string $domain
 * @property string $metric_key
 * @property string $value
 * @property Carbon $occurred_at
 * @property string|null $owner_id
 * @property string|null $source_event
 * @property array<string, mixed>|null $dimensions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AnalyticsEvent extends BaseModel
{
    protected $table = 'analytics_events';

    /** @var list<string> */
    protected $fillable = [
        'domain',
        'metric_key',
        'value',
        'occurred_at',
        'owner_id',
        'source_event',
        'dimensions',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'domain' => AnalyticsDomain::class,
            'value' => 'decimal:4',
            'occurred_at' => 'datetime',
            'dimensions' => 'array',
        ]);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
