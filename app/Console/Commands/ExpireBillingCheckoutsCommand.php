<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\CheckoutExpiryService;
use Illuminate\Console\Command;

final class ExpireBillingCheckoutsCommand extends Command
{
    protected $signature = 'billing:expire-checkouts {--batch=}';

    protected $description = 'Expire unpaid billing checkout sessions and orders';

    public function handle(CheckoutExpiryService $service): int
    {
        $batch = (int) ($this->option('batch') ?: config('billing.checkout.expiry_batch_size', 100));
        $this->info("Expired {$service->expire($batch)} checkout(s).");

        return self::SUCCESS;
    }
}
