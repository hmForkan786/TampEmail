<?php

declare(strict_types=1);

namespace App\Services\Commercial;

use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Outbound\OutboundNotificationService;
use Illuminate\Support\Facades\Cache;

/** One notification per usage threshold crossing to prevent alert spam. */
final class CommercialThresholdNotificationService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
    ) {}

    public function evaluate(User $user, string $featureKey, int $used, int $limit, string $periodKey): void
    {
        if ($limit <= 0) {
            return;
        }

        $percentage = (int) min(100, (int) floor(($used / $limit) * 100));

        foreach ((array) config('commercial.usage_thresholds', [80, 90, 100]) as $threshold) {
            if (! is_int($threshold) && ! is_numeric($threshold)) {
                continue;
            }

            $threshold = (int) $threshold;
            if ($percentage < $threshold) {
                continue;
            }

            $this->notifyOnce($user, $featureKey, $threshold, $periodKey, $percentage, $used, $limit);
        }
    }

    private function notifyOnce(
        User $user,
        string $featureKey,
        int $threshold,
        string $periodKey,
        int $percentage,
        int $used,
        int $limit,
    ): void {
        $idempotency = implode(':', ['commercial', 'threshold', $user->id, $featureKey, (string) $threshold, $periodKey]);

        if (! Cache::add('commercial-threshold:'.$idempotency, 1, now()->addDays(35))) {
            return;
        }

        $this->audit->write('commercial.usage_threshold_crossed', (string) $user->getKey(), null, null, null, [
            'feature' => $featureKey,
            'threshold' => $threshold,
            'percentage' => $percentage,
            'used' => $used,
            'limit' => $limit,
            'period_key' => $periodKey,
            'idempotency_key' => $idempotency,
        ]);

        if ($featureKey === 'outbound_messages_per_period') {
            $event = $threshold >= 100 ? 'outbound.usage_exhausted' : 'outbound.usage_warning';
            app(OutboundNotificationService::class)->notify(
                $user,
                $event,
                null,
                ['percentage' => max($percentage, $threshold)],
                $event.':'.$user->id.':'.$periodKey.':'.$threshold,
            );
        }
    }
}
