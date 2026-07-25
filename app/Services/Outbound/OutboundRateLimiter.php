<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Enums\OutboundMessageState;
use App\Exceptions\OutboundSendException;
use App\Models\OutboundMessage;
use App\Models\User;

final class OutboundRateLimiter
{
    public function assertWithinLimits(User $user): void
    {
        $perHour = (int) config('outbound.messages_per_hour', 30);
        $perDay = (int) config('outbound.messages_per_day', 200);

        if ($perHour > 0) {
            $hourCount = OutboundMessage::query()
                ->where('user_id', $user->getKey())
                ->where('created_at', '>=', now()->subHour())
                ->whereNotIn('state', [OutboundMessageState::Cancelled->value])
                ->count();

            if ($hourCount >= $perHour) {
                throw new OutboundSendException('rate_limit_hour', 'Hourly outbound message limit exceeded.', 429);
            }
        }

        if ($perDay > 0) {
            $dayCount = OutboundMessage::query()
                ->where('user_id', $user->getKey())
                ->where('created_at', '>=', now()->subDay())
                ->whereNotIn('state', [OutboundMessageState::Cancelled->value])
                ->count();

            if ($dayCount >= $perDay) {
                throw new OutboundSendException('rate_limit_day', 'Daily outbound message limit exceeded.', 429);
            }
        }
    }
}
