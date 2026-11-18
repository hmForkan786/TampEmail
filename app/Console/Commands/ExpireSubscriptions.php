<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Subscription\ExpireSubscriptionsService;
use Illuminate\Console\Command;

final class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire {--dry-run} {--batch=100}';

    protected $description = 'Expire ended access-granting subscriptions in bounded batches.';

    public function handle(ExpireSubscriptionsService $service): int
    {
        $result = $service->process((bool) $this->option('dry-run'), (int) $this->option('batch'));
        $this->table(['metric', 'value'], collect($result)->map(fn ($value, $key) => [$key, is_array($value) ? implode(', ', $value) : (string) $value])->values()->all());

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
