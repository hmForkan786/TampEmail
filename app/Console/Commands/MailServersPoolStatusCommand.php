<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MailServer\MailServerPoolMonitor;
use Illuminate\Console\Command;

final class MailServersPoolStatusCommand extends Command
{
    protected $signature = 'mail-servers:pool-status {--pool= : Optional pool_key filter} {--json : Emit JSON}';

    protected $description = 'Report mail server pool status, health scores, and utilization (safe output).';

    public function handle(MailServerPoolMonitor $monitor): int
    {
        $pool = $this->option('pool');
        $snapshot = $monitor->snapshot(is_string($pool) && $pool !== '' ? $pool : null);

        if ($this->option('json')) {
            $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line('evaluated_at: '.$snapshot['evaluated_at']);
        foreach ($snapshot['summary'] as $key => $value) {
            $this->line($key.': '.$value);
        }

        return self::SUCCESS;
    }
}
