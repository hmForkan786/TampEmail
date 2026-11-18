<?php

declare(strict_types=1);

namespace App\Services\Affiliates;

use App\Enums\AffiliateCommissionPlanStatus;
use App\Enums\AffiliateProfileStatus;
use App\Enums\AffiliateRegistrationMode;
use App\Exceptions\Affiliates\AffiliateNotEligibleException;
use App\Exceptions\Affiliates\AffiliateRegistrationException;
use App\Models\AffiliateCommissionPlan;
use App\Models\AffiliateProfile;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Handles the affiliate application lifecycle: submission, review, and
 * status transitions performed by administrators.
 */
final class AffiliateRegistrationService
{
    public function __construct(
        private readonly AffiliateCodeGenerator $codeGenerator,
        private readonly AuditLogWriter $audit,
        private readonly AffiliateNotificationService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(User $user, array $data): AffiliateProfile
    {
        if (config('affiliates.enabled') !== true) {
            throw new AffiliateNotEligibleException('Affiliate program is not enabled.');
        }

        $mode = AffiliateRegistrationMode::tryFrom((string) config('affiliates.registration_mode'))
            ?? AffiliateRegistrationMode::Disabled;

        if ($mode === AffiliateRegistrationMode::Disabled) {
            throw new AffiliateRegistrationException('Affiliate registration is currently disabled.');
        }

        $existing = AffiliateProfile::query()->where('user_id', $user->getKey())->first();

        if ($existing instanceof AffiliateProfile) {
            if ($existing->status === AffiliateProfileStatus::Pending) {
                return $existing;
            }

            throw new AffiliateRegistrationException('An affiliate profile already exists for this user.');
        }

        return DB::transaction(function () use ($user, $data, $mode): AffiliateProfile {
            $status = $mode === AffiliateRegistrationMode::Automatic
                ? AffiliateProfileStatus::Active
                : AffiliateProfileStatus::Pending;

            $plan = AffiliateCommissionPlan::query()
                ->where('status', AffiliateCommissionPlanStatus::Active->value)
                ->orderBy('created_at')
                ->first();

            $profile = AffiliateProfile::query()->create([
                'user_id' => $user->getKey(),
                'affiliate_code' => $this->codeGenerator->generate(),
                'status' => $status,
                'commission_plan_id' => $plan?->getKey(),
                'approved_at' => $status === AffiliateProfileStatus::Active ? now() : null,
                'promotion_channel' => $this->sanitizeShort($data['promotion_channel'] ?? null, 100),
                'website_url' => $this->sanitizeUrl($data['website_url'] ?? null),
                'audience_description' => $this->sanitizeLong($data['audience_description'] ?? null, 2000),
                'expected_traffic' => $this->sanitizeShort($data['expected_traffic'] ?? null, 100),
                'application_notes' => $this->sanitizeLong($data['application_notes'] ?? null, 2000),
            ]);

            $this->audit->write('affiliate.application_submitted', $user->getKey(), $profile, null, [
                'status' => $status->value,
                'registration_mode' => $mode->value,
            ]);

            if ($status === AffiliateProfileStatus::Active) {
                $this->audit->write('affiliate.approved', $user->getKey(), $profile, null, [
                    'reason' => 'automatic_registration_mode',
                ]);
            }

            return $profile;
        });
    }

    public function approve(AffiliateProfile $profile, User $admin): AffiliateProfile
    {
        return DB::transaction(function () use ($profile, $admin): AffiliateProfile {
            $locked = $this->lock($profile);

            if ($locked->status === AffiliateProfileStatus::Active) {
                return $locked;
            }

            if (! in_array($locked->status, [AffiliateProfileStatus::Pending, AffiliateProfileStatus::Suspended], true)) {
                throw new AffiliateRegistrationException('Only pending or suspended affiliate profiles can be approved.');
            }

            $old = ['status' => $locked->status->value];

            $locked->forceFill([
                'status' => AffiliateProfileStatus::Active,
                'approved_by' => $admin->getKey(),
                'approved_at' => now(),
                'suspended_at' => null,
                'rejected_at' => null,
            ])->save();

            $this->audit->write('affiliate.approved', $admin->getKey(), $locked, $old, ['status' => AffiliateProfileStatus::Active->value]);

            if ($locked->user instanceof User) {
                $this->notifications->notify($locked->user, 'affiliate.application_approved', [], 'affiliate-approved:'.$locked->getKey());
            }

            return $locked;
        });
    }

    public function reject(AffiliateProfile $profile, User $admin, ?string $reason): AffiliateProfile
    {
        return DB::transaction(function () use ($profile, $admin, $reason): AffiliateProfile {
            $locked = $this->lock($profile);

            if ($locked->status !== AffiliateProfileStatus::Pending) {
                throw new AffiliateRegistrationException('Only pending affiliate applications can be rejected.');
            }

            $old = ['status' => $locked->status->value];
            $metadata = $locked->metadata ?? [];
            $metadata['rejection_reason'] = $this->sanitizeLong($reason, 500);

            $locked->forceFill([
                'status' => AffiliateProfileStatus::Rejected,
                'rejected_at' => now(),
                'metadata' => $metadata,
            ])->save();

            $this->audit->write('affiliate.rejected', $admin->getKey(), $locked, $old, [
                'status' => AffiliateProfileStatus::Rejected->value,
                'reason' => $metadata['rejection_reason'],
            ]);

            if ($locked->user instanceof User) {
                $this->notifications->notify($locked->user, 'affiliate.application_rejected', [], 'affiliate-rejected:'.$locked->getKey());
            }

            return $locked;
        });
    }

    public function suspend(AffiliateProfile $profile, User $admin, ?string $reason): AffiliateProfile
    {
        return DB::transaction(function () use ($profile, $admin, $reason): AffiliateProfile {
            $locked = $this->lock($profile);

            if ($locked->status !== AffiliateProfileStatus::Active) {
                throw new AffiliateRegistrationException('Only active affiliate profiles can be suspended.');
            }

            $old = ['status' => $locked->status->value];
            $metadata = $locked->metadata ?? [];
            $metadata['suspension_reason'] = $this->sanitizeLong($reason, 500);

            $locked->forceFill([
                'status' => AffiliateProfileStatus::Suspended,
                'suspended_at' => now(),
                'metadata' => $metadata,
            ])->save();

            $this->audit->write('affiliate.suspended', $admin->getKey(), $locked, $old, [
                'status' => AffiliateProfileStatus::Suspended->value,
                'reason' => $metadata['suspension_reason'],
            ]);

            if ($locked->user instanceof User) {
                $this->notifications->notify($locked->user, 'affiliate.account_suspended', [], 'affiliate-suspended:'.$locked->getKey().':'.now()->timestamp);
            }

            return $locked;
        });
    }

    public function reactivate(AffiliateProfile $profile, User $admin): AffiliateProfile
    {
        return DB::transaction(function () use ($profile, $admin): AffiliateProfile {
            $locked = $this->lock($profile);

            if ($locked->status !== AffiliateProfileStatus::Suspended) {
                throw new AffiliateRegistrationException('Only suspended affiliate profiles can be reactivated.');
            }

            $old = ['status' => $locked->status->value];

            $locked->forceFill([
                'status' => AffiliateProfileStatus::Active,
                'suspended_at' => null,
            ])->save();

            $this->audit->write('affiliate.reactivated', $admin->getKey(), $locked, $old, ['status' => AffiliateProfileStatus::Active->value]);

            return $locked;
        });
    }

    public function close(AffiliateProfile $profile, User $admin): AffiliateProfile
    {
        return DB::transaction(function () use ($profile, $admin): AffiliateProfile {
            $locked = $this->lock($profile);

            if ($locked->status === AffiliateProfileStatus::Closed) {
                return $locked;
            }

            $old = ['status' => $locked->status->value];

            $locked->forceFill([
                'status' => AffiliateProfileStatus::Closed,
                'closed_at' => now(),
            ])->save();

            $this->audit->write('affiliate.closed', $admin->getKey(), $locked, $old, ['status' => AffiliateProfileStatus::Closed->value]);

            return $locked;
        });
    }

    /** Explicit admin-triggered code rotation. Never performed implicitly. */
    public function rotateCode(AffiliateProfile $profile, User $admin): AffiliateProfile
    {
        return DB::transaction(function () use ($profile, $admin): AffiliateProfile {
            $locked = $this->lock($profile);

            if ($locked->status === AffiliateProfileStatus::Closed) {
                throw new AffiliateRegistrationException('Cannot rotate the code of a closed affiliate profile.');
            }

            $oldCode = $locked->affiliate_code;
            $locked->forceFill(['affiliate_code' => $this->codeGenerator->generate()])->save();

            $this->audit->write('affiliate.code_rotated', $admin->getKey(), $locked, ['affiliate_code' => $oldCode], ['affiliate_code' => $locked->affiliate_code]);

            return $locked;
        });
    }

    private function lock(AffiliateProfile $profile): AffiliateProfile
    {
        return AffiliateProfile::query()->whereKey($profile->getKey())->lockForUpdate()->firstOrFail();
    }

    private function sanitizeShort(mixed $value, int $limit): ?string
    {
        return $this->sanitizeText($value, $limit);
    }

    private function sanitizeLong(mixed $value, int $limit): ?string
    {
        return $this->sanitizeText($value, $limit);
    }

    private function sanitizeText(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim(strip_tags($value));

        return $clean === '' ? null : Str::limit($clean, $limit, '');
    }

    private function sanitizeUrl(mixed $value): ?string
    {
        $clean = $this->sanitizeText($value, 255);

        if ($clean === null) {
            return null;
        }

        if (stripos($clean, 'javascript:') === 0) {
            return null;
        }

        if (preg_match('#^https?://#i', $clean) !== 1) {
            return null;
        }

        return $clean;
    }
}
