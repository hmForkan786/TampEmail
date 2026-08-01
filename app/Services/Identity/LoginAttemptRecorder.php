<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Records hashed login attempts for security history.
 */
final class LoginAttemptRecorder
{
    public function __construct(
        private readonly IdentityHashingService $hashing,
    ) {}

    public function record(
        Request $request,
        string $email,
        bool $success,
        ?User $user = null,
        ?string $failureReason = null,
    ): LoginAttempt {
        return LoginAttempt::query()->create([
            'user_id' => $user?->getKey(),
            'email_hash' => $this->hashing->hashEmail($email),
            'success' => $success,
            'failure_reason_code' => $failureReason,
            'ip_hash' => $this->hashing->hashIp($request->ip()),
            'user_agent_hash' => $this->hashing->hashUserAgent($request->userAgent()),
            'occurred_at' => now(),
        ]);
    }

    public function pruneOlderThanDays(int $days, int $limit = 500): int
    {
        if ($days < 1) {
            return 0;
        }

        return LoginAttempt::query()
            ->where('occurred_at', '<', now()->subDays($days))
            ->orderBy('occurred_at')
            ->limit($limit)
            ->delete();
    }
}
