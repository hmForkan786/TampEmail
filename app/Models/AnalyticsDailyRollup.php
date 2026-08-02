<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnalyticsDomain;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Daily aggregated metric bucket (platform or owner scope).
 *
 * @property string $id
 * @property Carbon $bucket_date
 * @property string $domain
 * @property string $metric_key
 * @property string $value
 * @property string $scope_key
 * @property string|null $owner_id
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AnalyticsDailyRollup extends BaseModel
{
    protected $table = 'analytics_daily_rollups';

    /** @var list<string> */
    protected $fillable = [
        'bucket_date',
        'domain',
        'metric_key',
        'value',
        'scope_key',
        'owner_id',
        'meta',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'bucket_date' => 'date',
            'domain' => AnalyticsDomain::class,
            'value' => 'decimal:4',
            'meta' => 'array',
        ]);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isPlatformScope(): bool
    {
        return $this->scope_key === 'platform';
    }
}
