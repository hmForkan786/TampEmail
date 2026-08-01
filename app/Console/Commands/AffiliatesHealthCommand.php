<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Affiliates\AffiliateHealthCheckService;
use Illuminate\Console\Command;

final class AffiliatesHealthCommand extends Command
{
    protected $signature = 'affiliates:health';

    protected $description = 'Check affiliate program subsystem health (JSON)';

    public function handle(AffiliateHealthCheckService $health): int
    {
        $result = $health->check();
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
