<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Outbound\OutboundPruneService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Fail-closed outbound retention/privacy prune.
 *
 * Dry-run by default (or whenever --confirm is absent): reports safe,
 * content-free counts only and never mutates. Passing --confirm performs
 * the bounded, per-category prune described in
 * docs/OUTBOUND_RETENTION_POLICY.md. Output is always metadata-only: no
 * message body, recipient address, secret, or storage path is ever
 * printed.
 */
final class PruneOutboundCommand extends Command
{
    protected $signature = 'outbound:prune
                            {--dry-run : Report without mutating anything}
                            {--confirm : Permit redaction/pruning/hard-delete after policy checks}
                            {--batch= : Rows per bounded batch, per category}';

    protected $description = 'Prune/redact outbound messages, delivery attempts, and provider events per retention policy.';

    public function handle(OutboundPruneService $service): int
    {
        $lock = Cache::lock('outbound:prune', 600);

        if (! $lock->get()) {
            $this->warn('Another outbound prune run is in progress.');

            return self::SUCCESS;
        }

        try {
            $confirm = (bool) $this->option('confirm');
            $dryRun = (bool) $this->option('dry-run') || ! $confirm;
            $batchSize = (int) ($this->option('batch') ?: config('outbound_retention.batch_size', 500));

            $report = $service->prune($dryRun, $confirm, $batchSize);

            foreach ([
                'eligible_content_redaction',
                'content_redacted',
                'eligible_attempts',
                'attempts_deleted',
                'eligible_provider_events',
                'provider_events_deleted',
                'eligible_hard_delete',
                'messages_hard_deleted',
                'held',
                'skipped',
                'failed',
            ] as $key) {
                $this->line($key.': '.$report[$key]);
            }

            $this->line('blocked: '.($report['blocked'] ? 'yes' : 'no'));
            $this->line('blocked_reason: '.($report['blocked_reason'] ?? 'none'));
            $this->line('mode: '.($dryRun ? 'dry-run' : 'confirm'));
            $this->line('duration: '.number_format((float) $report['duration'], 3).'s');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
