<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\InviteService;
use Illuminate\Console\Command;

final class IdentityExpireInvitesCommand extends Command
{
    protected $signature = 'identity:expire-invites {--dry-run : Report only}';

    protected $description = 'Revoke expired registration invites';

    public function handle(InviteService $invites): int
    {
        $limit = (int) config('identity.prune.batch_size', 200);

        if ($this->option('dry-run')) {
            $this->info("Would expire up to {$limit} invites.");

            return self::SUCCESS;
        }

        $count = $invites->expireDue($limit);
        $this->info("Expired {$count} invite(s).");

        return self::SUCCESS;
    }
}
