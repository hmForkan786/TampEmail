<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Affiliates\AffiliateAttributionService;
use Illuminate\Console\Command;

final class AffiliatesExpireAttributionsCommand extends Command
{
    protected $signature = 'affiliates:expire-attributions {--limit=}';

    protected $description = 'Mark active affiliate attributions past their expiry as expired';

    public function handle(AffiliateAttributionService $attributions): int
    {
        $limit = (int) ($this->option('limit') ?: config('affiliates.batch_sizes.attribution_expire', 500));

        $count = $attributions->expireDue($limit);

        $this->info("Expired {$count} attribution(s).");

        return self::SUCCESS;
    }
}
