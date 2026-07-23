<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Inbox\ExpireInboxesService;
use Illuminate\Console\Command;

final class ExpireInboxes extends Command
{
    protected $signature = 'inboxes:expire {--dry-run} {--confirm} {--batch= : Maximum records per batch}';
    protected $description = 'Report or deactivate expired inboxes in bounded batches.';

    public function handle(ExpireInboxesService $service): int
    {
        $confirm = (bool) $this->option('confirm') && ! (bool) $this->option('dry-run');
        $result = $service->process($confirm, $this->option('batch') !== null ? (int) $this->option('batch') : null);
        $this->table(['metric', 'value'], collect($result)->map(fn ($value, $key) => [$key, is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value)])->values()->all());
        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
