<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Affiliates\AffiliateCommissionMaturityService;
use Illuminate\Console\Command;

final class AffiliatesMatureCommissionsCommand extends Command
{
    protected $signature = 'affiliates:mature-commissions {--dry-run} {--limit=}';

    protected $description = 'Promote pending affiliate commission entries to available once their hold period elapses';

    public function handle(AffiliateCommissionMaturityService $maturity): int
    {
        $limit = (int) ($this->option('limit') ?: config('affiliates.batch_sizes.maturity', 200));
        $dryRun = (bool) $this->option('dry-run');

        $result = $maturity->mature($limit, $dryRun);

        $this->info(sprintf(
            '%sMatured %d commission entry(s), skipped %d.',
            $dryRun ? '[dry-run] ' : '',
            $result['matured'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
