<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Outbound\OutboundProviderEventProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class ReconcileUnmatchedOutboundProviderEventsCommand extends Command
{
    protected $signature = 'outbound:reconcile-unmatched-events
                            {--limit= : Maximum unmatched events to re-evaluate in this run}';

    protected $description = 'Retry correlation for unmatched outbound provider events within a bounded recent window.';

    public function handle(OutboundProviderEventProcessor $processor): int
    {
        $lock = Cache::lock('outbound:reconcile-unmatched-events', 300);
        if (! $lock->get()) {
            $this->warn('Another unmatched-event reconciliation run is in progress.');

            return self::SUCCESS;
        }

        try {
            $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
            $summary = $processor->reconcileUnmatched($limit);

            $this->line('evaluated: '.$summary['evaluated']);
            $this->line('matched: '.$summary['matched']);

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
