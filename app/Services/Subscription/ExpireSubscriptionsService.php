<?php

declare(strict_types=1);

namespace App\Services\Subscription;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ExpireSubscriptionsService
{
    public function __construct(private readonly AuditLogWriter $audit) {}

    /** @return array{eligible:int,processed:int,skipped:int,failed:int,batches:int,sample_ids:list<string>} */
    public function process(bool $dryRun = false, int $batchSize = 100): array
    {
        $batchSize = max(1, min(1000, $batchSize));
        $attempted = [];
        $base = function () use (&$attempted) {
            $query = Subscription::query()->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trial])
                ->where(fn ($query) => $query->where('ends_at', '<=', now())->orWhere(fn ($trial) => $trial->where('status', SubscriptionStatus::Trial)->where('trial_ends_at', '<=', now())))
                ->whereNotIn('id', $attempted);

            return $query;
        };
        $result = ['eligible' => $base()->count(), 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'batches' => 0, 'sample_ids' => $base()->orderBy('id')->limit(10)->pluck('id')->all()];
        if ($dryRun) {
            return $result;
        }

        while (($ids = $base()->orderBy('id')->limit($batchSize)->pluck('id'))->isNotEmpty()) {
            $result['batches']++;
            foreach ($ids as $id) {
                $attempted[] = (string) $id;
                try {
                    $changed = DB::transaction(function () use ($id): bool {
                        $subscription = Subscription::query()->whereKey($id)->lockForUpdate()->first();
                        if ($subscription === null || ! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Trial], true)) {
                            return false;
                        }
                        $boundary = $subscription->status === SubscriptionStatus::Trial ? ($subscription->trial_ends_at ?? $subscription->ends_at) : $subscription->ends_at;
                        if ($boundary === null || $boundary->isAfter(now())) {
                            return false;
                        }
                        $old = $subscription->status;
                        $at = now();
                        $subscription->forceFill(['status' => SubscriptionStatus::Expired, 'auto_renew' => false, 'cancel_at_period_end' => false])->save();
                        $this->audit->write('subscription.expired', null, $subscription, ['status' => $old->value], ['status' => SubscriptionStatus::Expired->value], ['source' => 'scheduler', 'effective_at' => $boundary->toIso8601String()], $at);

                        return true;
                    });
                    $result[$changed ? 'processed' : 'skipped']++;
                } catch (Throwable) {
                    $result['failed']++;
                }
            }
        }

        return $result;
    }
}
