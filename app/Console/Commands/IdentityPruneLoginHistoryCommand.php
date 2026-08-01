<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\LoginAttemptRecorder;
use Illuminate\Console\Command;

final class IdentityPruneLoginHistoryCommand extends Command
{
    protected $signature = 'identity:prune-login-history {--dry-run : Report only} {--confirm : Required when prune is enabled}';

    protected $description = 'Prune aged login attempt history (feature-flagged)';

    public function handle(LoginAttemptRecorder $recorder): int
    {
        if (config('identity.prune.enabled', false) !== true && ! $this->option('dry-run')) {
            $this->warn('IDENTITY_PRUNE_ENABLED=false — refusing destructive prune (use --dry-run).');

            return self::SUCCESS;
        }

        $days = (int) config('identity.prune.login_history_days', 90);
        $limit = (int) config('identity.prune.batch_size', 200);

        if ($this->option('dry-run')) {
            $this->info("Would prune login attempts older than {$days} days (batch {$limit}).");

            return self::SUCCESS;
        }

        if (! $this->option('confirm')) {
            $this->error('Pass --confirm to prune login history.');

            return self::FAILURE;
        }

        $deleted = $recorder->pruneOlderThanDays($days, $limit);
        $this->info("Pruned {$deleted} login attempt row(s).");

        return self::SUCCESS;
    }
}
