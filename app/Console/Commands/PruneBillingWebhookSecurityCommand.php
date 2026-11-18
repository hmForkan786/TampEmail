<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\Webhook\WebhookReplayProtectionService;
use Illuminate\Console\Command;

final class PruneBillingWebhookSecurityCommand extends Command
{
    protected $signature = 'billing:prune-webhook-security {--dry-run} {--batch=}';

    protected $description = 'Prune expired non-financial billing webhook replay records';

    public function handle(WebhookReplayProtectionService $replay): int
    {
        $batch = min(5000, max(1, (int) ($this->option('batch') ?: config('billing.webhook_security.prune_batch_size', 500))));
        $count = $replay->prune($batch, (bool) $this->option('dry-run'));
        $this->info(sprintf('%s %d expired replay nonce record(s).', $this->option('dry-run') ? 'Would prune' : 'Pruned', $count));

        return self::SUCCESS;
    }
}
