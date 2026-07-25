<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Exceptions\OutboundSendException;
use App\Models\Inbox;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\Cache;

/**
 * Staged rollout / emergency-stop gate layered on top of the existing
 * `outbound.enabled` / `*_enabled` kill switches (Prompt 615).
 *
 * Evaluation order (all fail closed):
 *  1. Emergency stop — overrides every enablement, checked first.
 *  2. Rollout mode: disabled|canary|percentage|enabled. Unsupported values
 *     fail closed exactly like "disabled".
 *
 * Percentage assignment is a deterministic hash of the user id, never
 * per-request randomness — the same user always lands on the same side of
 * a given percent boundary.
 *
 * `config/outbound.php` (env-driven) supplies the deployment default.
 * An authorized operator can additionally apply a live, audited override
 * (e.g. flipping emergency stop instantly without a redeploy) via
 * {@see setEmergencyStop()} / {@see setRollout()}; overrides live in cache
 * and take priority over the env default until cleared.
 *
 * This service never bypasses domain verification, suppression, quota, or
 * worker readiness; those remain independent checks in
 * {@see OutboundAuthorizationService} and the send/reply/forward actions.
 */
final class OutboundLaunchControlService
{
    private const CACHE_MODE_KEY = 'outbound:rollout:override:mode';

    private const CACHE_PERCENT_KEY = 'outbound:rollout:override:percent';

    private const CACHE_EMERGENCY_STOP_KEY = 'outbound:rollout:override:emergency_stop';

    public function __construct(
        private readonly OutboundCanaryService $canaries,
        private readonly AuditLogWriter $audit,
    ) {}

    public function isEmergencyStopped(): bool
    {
        $override = Cache::get(self::CACHE_EMERGENCY_STOP_KEY);
        if (is_bool($override)) {
            return $override;
        }

        return (bool) config('outbound.rollout.emergency_stop', true);
    }

    public function mode(): string
    {
        $override = Cache::get(self::CACHE_MODE_KEY);
        if (is_string($override) && $override !== '') {
            return strtolower(trim($override));
        }

        return strtolower(trim((string) config('outbound.rollout.mode', 'disabled')));
    }

    public function isSupportedMode(?string $mode = null): bool
    {
        $supported = (array) config('outbound.rollout.supported_modes', ['disabled', 'canary', 'percentage', 'enabled']);

        return in_array($mode ?? $this->mode(), $supported, true);
    }

    public function percent(): int
    {
        return max(0, min(100, $this->rawPercent()));
    }

    /**
     * Unclamped effective percent (override if set, else the raw env
     * value) — used by the config validator to surface out-of-range
     * misconfiguration instead of silently coercing it.
     */
    public function rawPercent(): int
    {
        $override = Cache::get(self::CACHE_PERCENT_KEY);
        if (is_int($override)) {
            return $override;
        }

        return (int) config('outbound.rollout.percent', 0);
    }

    public function hasLiveOverrides(): bool
    {
        return Cache::has(self::CACHE_MODE_KEY) || Cache::has(self::CACHE_PERCENT_KEY) || Cache::has(self::CACHE_EMERGENCY_STOP_KEY);
    }

    /**
     * Instantly flips emergency stop (cache-backed, audited). Always
     * allowed — pausing outbound is never blocked by validation, but
     * lifting the stop still leaves the rollout-mode gate in place.
     */
    public function setEmergencyStop(bool $stopped, User $actor): void
    {
        $before = $this->isEmergencyStopped();
        Cache::forever(self::CACHE_EMERGENCY_STOP_KEY, $stopped);

        $this->audit->write(
            'outbound.launch_emergency_stop_changed',
            (string) $actor->getKey(),
            null,
            ['emergency_stop' => $before],
            ['emergency_stop' => $stopped],
            [],
        );
    }

    /**
     * Applies a live rollout mode/percent change after validating it
     * against the config validator's fail-closed rules (unsupported mode,
     * percent out of range, canary mode without canaries, or "live
     * traffic" modes without a ready transport/queue/verified domain).
     *
     * @throws OutboundSendException when the requested state is invalid.
     */
    public function setRollout(string $mode, int $percent, User $actor, OutboundLaunchConfigValidator $validator): void
    {
        $mode = strtolower(trim($mode));

        if (! $this->isSupportedMode($mode)) {
            throw new OutboundSendException('outbound_rollout_mode_invalid', 'Unsupported rollout mode.', 422);
        }

        if ($percent < 0 || $percent > 100) {
            throw new OutboundSendException('outbound_rollout_percent_out_of_range', 'Rollout percent must be between 0 and 100.', 422);
        }

        if ($mode === 'canary' && ! $this->canaries->hasActiveCanaries()) {
            throw new OutboundSendException('canary_mode_without_canaries', 'Add at least one active canary before switching to canary mode.', 422);
        }

        if (in_array($mode, ['canary', 'percentage', 'enabled'], true)) {
            $validation = $validator->validate();
            $liveTrafficErrors = array_values(array_intersect($validation['errors'], [
                'enabled_without_valid_transport',
                'enabled_without_ready_queues',
                'enabled_without_verified_domain',
                'enabled_without_webhook_secret',
            ]));
            if ($liveTrafficErrors !== []) {
                throw new OutboundSendException('rollout_prerequisites_not_met', 'Launch prerequisites are not met: '.implode(', ', $liveTrafficErrors), 422);
            }
        }

        $beforeMode = $this->mode();
        $beforePercent = $this->percent();

        Cache::forever(self::CACHE_MODE_KEY, $mode);
        Cache::forever(self::CACHE_PERCENT_KEY, $percent);

        $this->audit->write(
            'outbound.launch_rollout_changed',
            (string) $actor->getKey(),
            null,
            ['mode' => $beforeMode, 'percent' => $beforePercent],
            ['mode' => $mode, 'percent' => $percent],
            [],
        );
    }

    /**
     * Reverts all live overrides back to the env-configured defaults.
     */
    public function clearOverrides(User $actor): void
    {
        Cache::forget(self::CACHE_MODE_KEY);
        Cache::forget(self::CACHE_PERCENT_KEY);
        Cache::forget(self::CACHE_EMERGENCY_STOP_KEY);

        $this->audit->write(
            'outbound.launch_overrides_cleared',
            (string) $actor->getKey(),
            null,
            null,
            null,
            [],
        );
    }

    /**
     * @throws OutboundSendException when emergency-stopped, the rollout
     *                               mode excludes this caller, or the
     *                               configured mode is unsupported.
     */
    public function assertRolloutEligible(User $user, Inbox $inbox, ?string $apiKeyId = null): void
    {
        if ($this->isEmergencyStopped()) {
            throw new OutboundSendException('outbound_emergency_stop', 'Outbound email is temporarily stopped.', 503);
        }

        $mode = $this->mode();

        if (! $this->isSupportedMode($mode)) {
            throw new OutboundSendException('outbound_rollout_mode_invalid', 'Outbound rollout mode is not configured correctly.', 403);
        }

        match ($mode) {
            'enabled' => null,
            'disabled' => throw new OutboundSendException('outbound_rollout_disabled', 'Outbound email is not yet enabled for any accounts.', 403),
            'canary' => $this->assertCanary($user, $inbox, $apiKeyId),
            'percentage' => $this->assertWithinPercentage($user),
            default => throw new OutboundSendException('outbound_rollout_mode_invalid', 'Outbound rollout mode is not configured correctly.', 403),
        };
    }

    /**
     * Whether this identity is eligible under the *current* rollout mode,
     * without throwing. Used for read-only reporting/UI.
     */
    public function isEligible(User $user, Inbox $inbox, ?string $apiKeyId = null): bool
    {
        try {
            $this->assertRolloutEligible($user, $inbox, $apiKeyId);

            return true;
        } catch (OutboundSendException) {
            return false;
        }
    }

    /**
     * Deterministic percentage-bucket check, independent of the current
     * rollout mode — used for the "is_canary" flag/metrics so a designated
     * canary identity is tracked even while mode=percentage or enabled.
     */
    public function isCanary(User $user, Inbox $inbox, ?string $apiKeyId = null): bool
    {
        return $this->canaries->matches($user, $inbox, $apiKeyId);
    }

    private function assertCanary(User $user, Inbox $inbox, ?string $apiKeyId): void
    {
        if (! $this->canaries->matches($user, $inbox, $apiKeyId)) {
            throw new OutboundSendException('outbound_rollout_not_canary', 'Outbound email is only enabled for canary accounts.', 403);
        }
    }

    private function assertWithinPercentage(User $user): void
    {
        if (! $this->withinPercentage($user)) {
            throw new OutboundSendException('outbound_rollout_percentage_excluded', 'This account is not yet included in the outbound rollout.', 403);
        }
    }

    private function withinPercentage(User $user): bool
    {
        $percent = $this->percent();

        if ($percent <= 0) {
            return false;
        }

        if ($percent >= 100) {
            return true;
        }

        return $this->bucket($user) < $percent;
    }

    /**
     * Deterministic 0-99 bucket for a user, stable across requests/time.
     */
    private function bucket(User $user): int
    {
        return (int) (hexdec(substr(hash('sha256', 'outbound-rollout:'.$user->getKey()), 0, 8)) % 100);
    }
}
