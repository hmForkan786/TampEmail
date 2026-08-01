<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;

final class IdentityPruneUnverifiedUsersCommand extends Command
{
    protected $signature = 'identity:prune-unverified-users {--dry-run : Report only} {--confirm : Required when prune is enabled}';

    protected $description = 'Soft-delete stale pending unverified users (feature-flagged, retention-aware)';

    public function handle(): int
    {
        if (config('identity.prune.enabled', false) !== true && ! $this->option('dry-run')) {
            $this->warn('IDENTITY_PRUNE_ENABLED=false — refusing destructive prune (use --dry-run).');

            return self::SUCCESS;
        }

        $days = (int) config('identity.prune.unverified_retention_days', 7);
        $limit = (int) config('identity.prune.batch_size', 200);

        $query = User::query()
            ->where('status', UserStatus::Pending)
            ->whereNull('email_verified_at')
            ->where('created_at', '<', now()->subDays($days))
            ->orderBy('created_at')
            ->limit($limit);

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("Would soft-delete {$count} unverified pending user(s) older than {$days} days.");

            return self::SUCCESS;
        }

        if (! $this->option('confirm')) {
            $this->error('Pass --confirm to prune unverified users.');

            return self::FAILURE;
        }

        $deleted = 0;
        $query->get()->each(function (User $user) use (&$deleted): void {
            $user->delete();
            $deleted++;
        });

        $this->info("Soft-deleted {$deleted} unverified pending user(s).");

        return self::SUCCESS;
    }
}
