<?php

declare(strict_types=1);

namespace App\Services\Inbox;

use App\DTOs\Inbox\InboxMutationContext;
use App\Models\Inbox;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;

final class ExpireInboxesService
{
    public function __construct(private readonly AuditLogWriter $audit, private readonly InboxLifetimePolicy $policy) {}

    /** @return array{eligible:int,processed:int,skipped:int,failed:int,batches:int,duration_ms:int,blocked_reason:string|null} */
    public function process(bool $confirm = false, ?int $batchSize = null): array
    {
        $context = InboxMutationContext::forScheduler();
        $context->assertSchedulerExpiration();

        $started = microtime(true);
        $batchSize = $batchSize ?? $this->policy->expirationBatchSize();
        if ($batchSize < 1) {
            return ['eligible' => 0, 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'batches' => 0, 'duration_ms' => 0, 'blocked_reason' => 'invalid_configuration'];
        }
        $eligible = Inbox::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->where('is_active', true)->count();
        $result = ['eligible' => $eligible, 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'batches' => 0, 'duration_ms' => 0, 'blocked_reason' => $confirm ? null : 'confirmation_required'];
        if (! $confirm) {
            $result['duration_ms'] = (int) ((microtime(true) - $started) * 1000);

            return $result;
        }

        while (true) {
            $ids = Inbox::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())->where('is_active', true)->limit($batchSize)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $result['batches']++;
            $processedBefore = $result['processed'];
            foreach ($ids as $id) {
                try {
                    $didProcess = false;
                    DB::transaction(function () use ($id, $context, &$didProcess): void {
                        $inbox = Inbox::query()->whereKey($id)->whereNotNull('expires_at')->where('expires_at', '<=', now())->where('is_active', true)->lockForUpdate()->first();
                        if ($inbox === null) {
                            return;
                        }
                        $at = now();
                        $inbox->forceFill(['is_active' => false])->save();
                        $inbox->delete();
                        $this->audit->write('inbox.expired', $context->actorUserId, $inbox, ['is_active' => true], ['is_active' => false], [
                            'source' => $context->source,
                            'api_key_id' => $context->apiKeyId,
                            'expiry_timestamp' => $inbox->expires_at?->toIso8601String(),
                            'processed_at' => $at->toIso8601String(),
                        ], $at);
                        $didProcess = true;
                    });
                    if ($didProcess) {
                        $result['processed']++;
                    } else {
                        $result['skipped']++;
                    }
                } catch (\Throwable) {
                    $result['failed']++;
                }
            }
            if ($result['processed'] === $processedBefore) {
                break;
            }
        }
        $result['duration_ms'] = (int) ((microtime(true) - $started) * 1000);

        return $result;
    }
}
