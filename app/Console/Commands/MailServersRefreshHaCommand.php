<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MailServer\MailServerHaRefreshService;
use Illuminate\Console\Command;

final class MailServersRefreshHaCommand extends Command
{
    protected $signature = 'mail-servers:refresh-ha {--limit= : Max servers to refresh}';

    protected $description = 'Refresh mail server health scores and complete idle drains (idempotent).';

    public function handle(MailServerHaRefreshService $service): int
    {
        $limit = $this->option('limit');
        $result = $service->refresh($limit !== null && $limit !== '' ? (int) $limit : null);
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
