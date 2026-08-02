<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Analytics\AnalyticsHealthCheckService;
use Illuminate\Console\Command;

final class AnalyticsHealthCommand extends Command
{
    protected $signature = 'analytics:health';

    protected $description = 'Check analytics aggregation pipeline health (JSON)';

    public function handle(AnalyticsHealthCheckService $health): int
    {
        $result = $health->check();
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return ($result['healthy'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
