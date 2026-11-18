<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\SslCommerz\SslCommerzHealthCheckService;
use Illuminate\Console\Command;

final class SslCommerzHealthCommand extends Command
{
    protected $signature = 'billing:sslcommerz-health';

    protected $description = 'Check SSLCommerz TLS reachability and safe store configuration';

    public function handle(SslCommerzHealthCheckService $health): int
    {
        $result = $health->check();
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
