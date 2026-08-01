<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\AccountRecoveryReasonCode;
use App\Enums\AccountRecoveryStatus;
use App\Models\AccountRecoveryRequest;
use App\Models\User;
use App\Notifications\Identity\RecoveryRequestReceivedNotification;
use App\Notifications\Identity\RecoveryStatusNotification;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Admin-assisted account recovery (no automated KYC in Prompt 664).
 */
final class AccountRecoveryService
{
    public function __construct(
        private readonly IdentityHashingService $hashing,
        private readonly AuditLogWriter $audit,
        private readonly IdentityAnalyticsRecorder $analytics,
        private readonly EmailChangeService $emailChange,
        private readonly SessionManagementService $sessions,
        private readonly ApiKeyRepositoryInterface $apiKeys,
    ) {}

    /**
     * Always returns a generic acknowledgement.
     *
     * @param  array{claimed_email: string, reason_code: string, new_email?: string|null, evidence_notes?: string|null}  $input
     */
    public function submit(array $input, ?string $ip): AccountRecoveryRequest
    {
        $email = strtolower(trim((string) $input['claimed_email']));
        $reason = AccountRecoveryReasonCode::tryFrom((string) $input['reason_code'])
            ?? AccountRecoveryReasonCode::Other;

        $user = User::query()->where('email', $email)->first();

        $newEmail = $input['new_email'] ?? null;
        $evidenceNotes = $input['evidence_notes'] ?? null;

        $request = AccountRecoveryRequest::query()->create([
            'user_id' => $user instanceof User ? $user->getKey() : null,
            'claimed_email_hash' => $this->hashing->hashEmail($email),
            'new_email_encrypted' => is_string($newEmail) && trim($newEmail) !== ''
                ? strtolower(trim($newEmail))
                : null,
            'status' => AccountRecoveryStatus::Submitted,
            'reason_code' => $reason,
            'evidence_notes_encrypted' => is_string($evidenceNotes) && trim($evidenceNotes) !== ''
                ? trim($evidenceNotes)
                : null,
            'submitted_ip_hash' => $this->hashing->hashIp($ip),
            'expires_at' => now()->addHours((int) config('identity.recovery.expire_hours', 72)),
            'review_history' => [[
                'action' => 'submitted',
                'at' => now()->toIso8601String(),
            ]],
        ]);

        $this->audit->write('identity.recovery_requested', $user instanceof User ? (string) $user->getKey() : null, $request, metadata: [
            'reason_code' => $reason->value,
            'has_user' => $user instanceof User,
        ]);
        $this->analytics->record('identity.account_recovery_started', $user instanceof User ? (string) $user->getKey() : null);

        if ($user instanceof User) {
            $user->notify(new RecoveryRequestReceivedNotification);
        }

        return $request;
    }

    public function startReview(AccountRecoveryRequest $request, User $admin): AccountRecoveryRequest
    {
        $this->assertAdmin($admin);
        $this->assertMutable($request);

        $request->appendReviewHistory([
            'action' => 'review_started',
            'by' => (string) $admin->getKey(),
            'at' => now()->toIso8601String(),
        ]);
        $request->forceFill([
            'status' => AccountRecoveryStatus::UnderReview,
            'reviewed_by' => $admin->getKey(),
            'reviewed_at' => now(),
        ])->save();

        $this->audit->write('identity.recovery_review_started', (string) $admin->getKey(), $request);

        return $request->fresh() ?? $request;
    }

    public function approve(AccountRecoveryRequest $request, User $admin): AccountRecoveryRequest
    {
        $this->assertAdmin($admin);
        $this->assertMutable($request);

        $dual = (bool) config('identity.recovery.dual_approval_email_change', false);
        $needsEmailChange = filled($request->new_email_encrypted);

        if ($dual && $needsEmailChange && $request->reviewed_by !== null
            && (string) $request->reviewed_by === (string) $admin->getKey()
            && $request->second_reviewed_by === null
            && $request->status === AccountRecoveryStatus::UnderReview
        ) {
            // First approval recorded; wait for second distinct admin.
            $request->appendReviewHistory([
                'action' => 'first_approval',
                'by' => (string) $admin->getKey(),
                'at' => now()->toIso8601String(),
            ]);
            $request->save();

            return $request->fresh() ?? $request;
        }

        if ($dual && $needsEmailChange && $request->reviewed_by !== null
            && (string) $request->reviewed_by !== (string) $admin->getKey()
            && $request->second_reviewed_by === null
        ) {
            $request->forceFill([
                'second_reviewed_by' => $admin->getKey(),
                'second_reviewed_at' => now(),
            ]);
        } elseif ($dual && $needsEmailChange && $request->second_reviewed_by === null && $request->reviewed_by === null) {
            $request->forceFill([
                'reviewed_by' => $admin->getKey(),
                'reviewed_at' => now(),
            ]);
            $request->appendReviewHistory([
                'action' => 'first_approval',
                'by' => (string) $admin->getKey(),
                'at' => now()->toIso8601String(),
            ]);
            $request->save();

            return $request->fresh() ?? $request;
        }

        if ($dual && $needsEmailChange && $request->second_reviewed_by === null
            && $request->reviewed_by !== null
            && (string) $request->reviewed_by === (string) $admin->getKey()
        ) {
            throw ValidationException::withMessages([
                'recovery' => __('A second distinct admin must approve email-change recovery.'),
            ]);
        }

        $request->appendReviewHistory([
            'action' => 'approved',
            'by' => (string) $admin->getKey(),
            'at' => now()->toIso8601String(),
        ]);
        $request->forceFill([
            'status' => AccountRecoveryStatus::Approved,
            'reviewed_by' => $request->reviewed_by ?? $admin->getKey(),
            'reviewed_at' => $request->reviewed_at ?? now(),
        ])->save();

        $this->audit->write('identity.recovery_approved', (string) $admin->getKey(), $request);

        $user = $request->user;
        if ($user instanceof User) {
            $user->notify(new RecoveryStatusNotification('approved'));
        }

        return $request->fresh() ?? $request;
    }

    public function reject(AccountRecoveryRequest $request, User $admin, ?string $note = null): AccountRecoveryRequest
    {
        $this->assertAdmin($admin);
        $this->assertMutable($request);

        $request->appendReviewHistory([
            'action' => 'rejected',
            'by' => (string) $admin->getKey(),
            'at' => now()->toIso8601String(),
            'note' => $note !== null ? substr($note, 0, 200) : null,
        ]);
        $request->forceFill([
            'status' => AccountRecoveryStatus::Rejected,
            'reviewed_by' => $admin->getKey(),
            'reviewed_at' => now(),
            'completed_at' => now(),
        ])->save();

        $this->audit->write('identity.recovery_rejected', (string) $admin->getKey(), $request);

        $user = $request->user;
        if ($user instanceof User) {
            $user->notify(new RecoveryStatusNotification('rejected'));
        }

        return $request->fresh() ?? $request;
    }

    /**
     * Complete an approved recovery: stage email change (if any), rotate sessions/keys, require password reset path.
     * Reviewer cannot mark completed without prior approval.
     */
    public function complete(AccountRecoveryRequest $request, User $admin): AccountRecoveryRequest
    {
        $this->assertAdmin($admin);

        if ($request->status !== AccountRecoveryStatus::Approved) {
            throw ValidationException::withMessages([
                'recovery' => __('Only approved recovery requests can be completed.'),
            ]);
        }

        return DB::transaction(function () use ($request, $admin): AccountRecoveryRequest {
            /** @var AccountRecoveryRequest $locked */
            $locked = AccountRecoveryRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== AccountRecoveryStatus::Approved) {
                throw ValidationException::withMessages([
                    'recovery' => __('Only approved recovery requests can be completed.'),
                ]);
            }

            $user = $locked->user_id
                ? User::query()->whereKey($locked->user_id)->lockForUpdate()->first()
                : null;

            if (! $user instanceof User) {
                throw ValidationException::withMessages([
                    'recovery' => __('Recovery target user is unavailable.'),
                ]);
            }

            $newEmail = $locked->new_email_encrypted;
            if (is_string($newEmail) && $newEmail !== '') {
                $this->emailChange->stagePendingEmail($user, $newEmail, $admin);
            }

            $this->sessions->revokeAllForUser($user);

            if (config('identity.recovery.revoke_api_keys_on_complete', true) === true) {
                $this->apiKeys->revokeAllUnrevokedForUser((string) $user->getKey(), now());
            }

            // Force password reset path: clear remember token; user must use forgot-password.
            $user->forceFill(['remember_token' => null])->save();

            $locked->appendReviewHistory([
                'action' => 'completed',
                'by' => (string) $admin->getKey(),
                'at' => now()->toIso8601String(),
                'password_reset_required' => true,
            ]);
            $locked->forceFill([
                'status' => AccountRecoveryStatus::Completed,
                'completed_at' => now(),
            ])->save();

            $this->audit->write('identity.recovery_completed', (string) $admin->getKey(), $locked);
            $this->analytics->record('identity.account_recovery_completed', (string) $user->getKey());

            $user->notify(new RecoveryStatusNotification('completed'));

            return $locked->fresh() ?? $locked;
        });
    }

    public function expireStale(int $limit = 200): int
    {
        $count = 0;
        AccountRecoveryRequest::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNotIn('status', [
                AccountRecoveryStatus::Completed->value,
                AccountRecoveryStatus::Rejected->value,
                AccountRecoveryStatus::Cancelled->value,
            ])
            ->orderBy('expires_at')
            ->limit($limit)
            ->get()
            ->each(function (AccountRecoveryRequest $request) use (&$count): void {
                $request->appendReviewHistory([
                    'action' => 'expired',
                    'at' => now()->toIso8601String(),
                ]);
                $request->forceFill([
                    'status' => AccountRecoveryStatus::Cancelled,
                    'completed_at' => now(),
                ])->save();
                $count++;
            });

        return $count;
    }

    private function assertAdmin(User $admin): void
    {
        if (! $admin->isPlatformAdmin()) {
            throw new RuntimeException('Only platform admins may review recovery requests.');
        }
    }

    private function assertMutable(AccountRecoveryRequest $request): void
    {
        if ($request->status->isTerminal()) {
            throw ValidationException::withMessages([
                'recovery' => __('This recovery request can no longer be modified.'),
            ]);
        }

        if ($request->expires_at !== null && $request->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'recovery' => __('This recovery request has expired.'),
            ]);
        }
    }
}
