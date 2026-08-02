<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnalyticsAggregationStatus;
use Illuminate\Support\Carbon;

/**
 * Tracks a single scheduled aggregation run for health / backlog monitoring.
 *
 * @property string $id
 * @property Carbon $bucket_date
 * @property AnalyticsAggregationStatus $status
 * @property int $metrics_written
 * @property int $events_ingested
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error_message
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AnalyticsAggregationRun extends BaseModel
{
    protected $table = 'analytics_aggregation_runs';

    /** @var list<string> */
    protected $fillable = [
        'bucket_date',
        'status',
        'metrics_written',
        'events_ingested',
        'started_at',
        'finished_at',
        'error_message',
        'meta',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'bucket_date' => 'date',
            'status' => AnalyticsAggregationStatus::class,
            'metrics_written' => 'integer',
            'events_ingested' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'meta' => 'array',
        ]);
    }
}
