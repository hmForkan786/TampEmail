<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Outbound\DispatchDueOutboundMessagesAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class OutboundDispatchScheduledCommand extends Command
{
    protected $signature = 'outbound:dispatch-scheduled {--batch= : Max messages}';

    protected $description = 'Dispatch due scheduled outbound messages to the delivery queue.';

    public function handle(DispatchDueOutboundMessagesAction $action): int
    {
        $lock = Cache::lock('outbound:dispatch-scheduled', 55);

        if (! $lock->get()) {
            $this->warn('Another scheduled dispatch run is in progress.');

            return self::SUCCESS;
        }

        try {
            $batchSize = (int) ($this->option('batch') ?: config('outbound.schedule.dispatch_batch_size', 50));
            $stats = $action->execute(max(1, $batchSize));

            foreach ($stats as $key => $value) {
                $this->line($key.': '.$value);
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
