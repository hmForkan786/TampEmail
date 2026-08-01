<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\Stripe\StripeHealthCheckService;
use Illuminate\Console\Command;

final class StripeHealthCommand extends Command
{
    protected $signature = 'billing:stripe-health';

    protected $description = 'Check Stripe account configuration and API reachability';

    public function handle(StripeHealthCheckService $health): int
    {
        $result = $health->check();
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
