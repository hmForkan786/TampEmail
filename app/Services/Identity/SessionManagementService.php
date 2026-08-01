<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\User;
use App\Notifications\Identity\SessionsRevokedNotification;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Database-session enumeration and revocation.
 *
 * When SESSION_DRIVER is not `database`, listing/limits fail safely (empty list / no-op limit).
 */
final class SessionManagementService
{
    public function __construct(
        private readonly IdentityHashingService $hashing,
        private readonly AuditLogWriter $audit,
        private readonly IdentityAnalyticsRecorder $analytics,
    ) {}

    public function supportsEnumeration(): bool
    {
        return config('session.driver') === 'database'
            || config('identity.sessions.enumeration_supported') === true;
    }

    /**
     * @return list<array{
     *     id: string,
     *     id_masked: string,
     *     is_current: bool,
     *     ip_address: string|null,
     *     user_agent: string|null,
     *     last_activity: int,
     *     last_activity_at: string|null
     * }>
     */
    public function listForUser(User $user, ?string $currentSessionId = null): array
    {
        if (! $this->supportsEnumeration()) {
            return [];
        }

        $rows = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $id = (string) $row->id;
            $result[] = [
                'id' => $id,
                'id_masked' => $this->hashing->maskSessionId($id),
                'is_current' => $currentSessionId !== null && hash_equals($currentSessionId, $id),
                'ip_address' => isset($row->ip_address) ? $this->approximateLocation((string) $row->ip_address) : null,
                'user_agent' => isset($row->user_agent) ? $this->summarizeUserAgent((string) $row->user_agent) : null,
                'last_activity' => (int) $row->last_activity,
                'last_activity_at' => isset($row->last_activity)
                    ? date('c', (int) $row->last_activity)
                    : null,
            ];
        }

        return $result;
    }

    public function revokeOne(User $user, string $sessionId, string $currentSessionId, bool $confirmPasswordOk): void
    {
        if (! $confirmPasswordOk) {
            throw ValidationException::withMessages([
                'password' => __('Please confirm your password.'),
            ]);
        }

        if (hash_equals($currentSessionId, $sessionId)) {
            throw ValidationException::withMessages([
                'session' => __('You cannot revoke the current session from this action. Use log out instead.'),
            ]);
        }

        if (! $this->supportsEnumeration()) {
            return;
        }

        $deleted = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->where('id', $sessionId)
            ->delete();

        if ($deleted > 0) {
            $this->audit->write('identity.sessions_revoked', (string) $user->getKey(), $user, metadata: [
                'scope' => 'one',
                'session_id_hash' => $this->hashing->hash($sessionId),
            ]);
            $this->analytics->record('identity.session_revoked', (string) $user->getKey());
        }
    }

    public function revokeOthers(User $user, string $currentSessionId, bool $confirmPasswordOk): int
    {
        if (! $confirmPasswordOk) {
            throw ValidationException::withMessages([
                'password' => __('Please confirm your password.'),
            ]);
        }

        $count = $this->revokeAllForUser($user, exceptSessionId: $currentSessionId);

        if ($count > 0) {
            $user->notify(new SessionsRevokedNotification($count));
        }

        return $count;
    }

    public function revokeAllForUser(User $user, ?string $exceptSessionId = null): int
    {
        if (! $this->supportsEnumeration()) {
            return 0;
        }

        $query = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey());

        if ($exceptSessionId !== null && $exceptSessionId !== '') {
            $query->where('id', '!=', $exceptSessionId);
        }

        $count = $query->delete();

        if ($count > 0) {
            $this->audit->write('identity.sessions_revoked', (string) $user->getKey(), $user, metadata: [
                'scope' => $exceptSessionId ? 'others' : 'all',
                'count' => $count,
            ]);
            $this->analytics->record('identity.session_revoked', (string) $user->getKey(), $count);
        }

        return $count;
    }

    /**
     * Enforce max active sessions after a successful login (0 = unlimited).
     */
    public function enforceLimitAfterLogin(User $user, string $currentSessionId): void
    {
        $max = (int) config('identity.sessions.max_active_web_sessions', 0);
        if ($max <= 0 || ! $this->supportsEnumeration()) {
            return;
        }

        $sessions = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->lockForUpdate()
            ->get();

        if ($sessions->count() <= $max) {
            return;
        }

        $keepIds = $sessions->take($max)->pluck('id')->all();
        if (! in_array($currentSessionId, $keepIds, true)) {
            $keepIds[count($keepIds) - 1] = $currentSessionId;
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->whereNotIn('id', $keepIds)
            ->delete();

        $this->audit->write('identity.sessions_revoked', (string) $user->getKey(), $user, metadata: [
            'scope' => 'session_limit',
            'max' => $max,
        ]);
    }

    private function summarizeUserAgent(string $ua): string
    {
        $ua = trim($ua);
        if ($ua === '') {
            return 'Unknown device';
        }

        return Str::limit($ua, 120, '…');
    }

    /**
     * Approximate location: never expose full IP; show truncated form only when present.
     */
    private function approximateLocation(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return 'Unknown';
        }

        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);

            return $parts[0].':…';
        }

        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            return $parts[0].'.'.$parts[1].'.x.x';
        }

        return 'Unknown';
    }
}
