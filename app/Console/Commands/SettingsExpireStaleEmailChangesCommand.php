<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Expire stale pending email changes without duplicating Identity prune jobs.
 */
final class SettingsExpireStaleEmailChangesCommand extends Command
{
    protected $signature = 'settings:expire-stale-email-changes
                            {--dry-run : Report without mutating}
                            {--confirm : Required for destructive cleanup}
                            {--batch= : Override batch size}';

    protected $description = 'Clear stale pending_email values after configured hours';

    public function handle(): int
    {
        if (config('settings.prune.enabled') !== true && ! $this->option('dry-run')) {
            $this->warn('SETTINGS_PRUNE_ENABLED is false. Use --dry-run or enable the feature flag.');

            return self::SUCCESS;
        }

        if (! $this->option('dry-run') && ! $this->option('confirm')) {
            $this->error('Refusing destructive cleanup without --confirm.');

            return self::FAILURE;
        }

        $hours = max(1, (int) config('settings.prune.expire_stale_email_changes_hours', 72));
        $batch = max(1, (int) ($this->option('batch') ?: config('settings.prune.batch_size', 100)));

        $query = User::query()
            ->whereNotNull('pending_email')
            ->where('updated_at', '<', now()->subHours($hours))
            ->orderBy('updated_at')
            ->limit($batch);

        $count = 0;
        foreach ($query->get() as $user) {
            if ($this->option('dry-run')) {
                $this->line('Would clear pending email for user '.$user->getKey());
                $count++;

                continue;
            }

            $user->forceFill([
                'pending_email' => null,
                'pending_email_verified_at' => null,
            ])->save();
            $count++;
        }

        $this->info(($this->option('dry-run') ? 'Matched' : 'Cleared').': '.$count);

        return self::SUCCESS;
    }
}
