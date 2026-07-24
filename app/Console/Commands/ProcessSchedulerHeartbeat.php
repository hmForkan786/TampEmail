<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\ProcessHeartbeatWriter;
use Illuminate\Console\Command;
use Throwable;

final class ProcessSchedulerHeartbeat extends Command
{
    protected $signature = 'processes:scheduler-heartbeat';

    protected $description = 'Write the scheduler process heartbeat.';

    public function handle(ProcessHeartbeatWriter $writer): int
    {
        try {
            if (! $writer->schedulerTick()) {
                $this->error('Scheduler heartbeat write failed.');

                return self::FAILURE;
            }

            $this->info('Scheduler heartbeat written.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Scheduler heartbeat unavailable.');

            return self::FAILURE;
        }
    }
}
