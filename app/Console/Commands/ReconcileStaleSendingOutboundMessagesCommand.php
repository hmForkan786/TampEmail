<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Outbound\OutboundStaleSendingReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class ReconcileStaleSendingOutboundMessagesCommand extends Command
{
    protected $signature = 'outbound:reconcile-stale-sending
                            {--limit= : Maximum stale messages to evaluate in this run}';

    protected $description = 'Requeue outbound messages stuck in sending only when safe; flag ambiguous outcomes for manual review.';

    public function handle(OutboundStaleSendingReconciliationService $service): int
    {
        $lock = Cache::lock('outbound:reconcile-stale-sending', 300);
        if (! $lock->get()) {
            $this->warn('Another stale-sending reconciliation run is in progress.');

            return self::SUCCESS;
        }

        try {
            $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
            $summary = $service->reconcile($limit);

            $this->line('evaluated: '.$summary['evaluated']);
            $this->line('requeued: '.$summary['requeued']);
            $this->line('flagged_ambiguous: '.$summary['flagged_ambiguous']);
            $this->line('failed_exhausted: '.$summary['failed_exhausted']);
            $this->line('skipped: '.$summary['skipped']);

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
