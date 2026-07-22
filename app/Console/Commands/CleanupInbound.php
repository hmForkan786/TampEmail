<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Inbound\InboundRetentionService;
use Illuminate\Console\Command;

final class CleanupInbound extends Command
{
    protected $signature = 'inbound:cleanup {--dry-run : Report without deleting} {--confirm : Permit deletion after policy checks} {--batch= : Rows per bounded batch}';
    protected $description = 'Perform fail-closed inbound retention cleanup.';

    public function handle(InboundRetentionService $service): int
    {
        $report = $service->cleanup((bool) $this->option('dry-run') || ! $this->option('confirm'), (bool) $this->option('confirm'), (int) ($this->option('batch') ?: config('inbound_retention.batch_size', 500)));
        foreach (['eligible','deleted','held','skipped','missing_files','failed'] as $key) $this->line($key.': '.$report[$key]);
        $this->line('blocked: '.($report['blocked'] ? 'yes' : 'no'));
        $this->line('blocked_reason: '.($report['blocked_reason'] ?? 'none'));
        $this->line('duration: '.number_format($report['duration'], 3).'s');
        return self::SUCCESS;
    }
}
