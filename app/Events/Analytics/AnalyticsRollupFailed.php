<?php

declare(strict_types=1);

namespace App\Events\Analytics;

use App\Models\AnalyticsAggregationRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class AnalyticsRollupFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly AnalyticsAggregationRun $run,
        public readonly string $message,
    ) {}
}
