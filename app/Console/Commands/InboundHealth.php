<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Inbound\InboundMetricsService;
use Illuminate\Console\Command;

final class InboundHealth extends Command
{
    protected $signature = 'inbound:health';
    protected $description = 'Report safe inbound processing health metrics.';

    public function handle(): int
    {
        try {
            $health = app(InboundMetricsService::class)->health();
        } catch (\Throwable) {
            $health = ['status' => 'failed', 'breaches' => ['metrics_unavailable']];
        }
        $this->line(json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $health['status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
    }
}
