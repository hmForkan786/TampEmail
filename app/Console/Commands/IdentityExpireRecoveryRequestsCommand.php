<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\AccountRecoveryService;
use Illuminate\Console\Command;

final class IdentityExpireRecoveryRequestsCommand extends Command
{
    protected $signature = 'identity:expire-recovery-requests {--dry-run : Report only}';

    protected $description = 'Cancel stale account recovery requests past expiry';

    public function handle(AccountRecoveryService $recovery): int
    {
        $limit = (int) config('identity.prune.batch_size', 200);

        if ($this->option('dry-run')) {
            $this->info("Would expire up to {$limit} recovery requests.");

            return self::SUCCESS;
        }

        $count = $recovery->expireStale($limit);
        $this->info("Expired {$count} recovery request(s).");

        return self::SUCCESS;
    }
}
