<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ads\AdHealthCheckService;
use Illuminate\Console\Command;

final class AdsHealthCommand extends Command
{
    protected $signature = 'ads:health';

    protected $description = 'Check ads management subsystem health (JSON)';

    public function handle(AdHealthCheckService $health): int
    {
        $result = $health->check();
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return ($result['healthy'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
