<?php

declare(strict_types=1);

namespace App\Services\Outbound;

/**
 * Shared schedule column clears for unschedule, cancel, and dispatch outcomes.
 */
final class OutboundScheduleFieldHelper
{
    /**
     * @return array<string, mixed>
     */
    public static function cleared(): array
    {
        return [
            'scheduled_at' => null,
            'scheduled_timezone' => null,
            'scheduled_by_user_id' => null,
            'schedule_version' => 0,
            'scheduled_claimed_at' => null,
            'schedule_defer_reason' => null,
            'schedule_next_attempt_at' => null,
        ];
    }
}
