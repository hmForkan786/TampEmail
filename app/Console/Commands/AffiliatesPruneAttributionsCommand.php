<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Affiliates\AffiliateAttributionService;
use Illuminate\Console\Command;

final class AffiliatesPruneAttributionsCommand extends Command
{
    protected $signature = 'affiliates:prune-attributions {--dry-run} {--limit=} {--confirm : Required to actually delete rows}';

    protected $description = 'Delete expired/invalidated affiliate attributions past the retention window';

    public function handle(AffiliateAttributionService $attributions): int
    {
        $limit = (int) ($this->option('limit') ?: config('affiliates.batch_sizes.attribution_prune', 500));
        $confirmed = (bool) $this->option('confirm');
        $dryRun = ! $confirmed || (bool) $this->option('dry-run');

        if (! $confirmed && ! $this->option('dry-run')) {
            $this->warn('Refusing to delete without --confirm; running in dry-run mode.');
        }

        $count = $attributions->pruneExpired($limit, $dryRun);

        $this->info(sprintf(
            '%s%d attribution(s) %s.',
            $dryRun ? '[dry-run] ' : '',
            $count,
            $dryRun ? 'would be pruned' : 'pruned',
        ));

        return self::SUCCESS;
    }
}
