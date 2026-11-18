<?php

declare(strict_types=1);

namespace App\Services\Billing\ManualCrypto;

use App\DTOs\Billing\WebhookRequestData;
use App\Enums\BillingOrderStatus;
use App\Enums\ManualCryptoClaimState;
use App\Enums\ManualCryptoEvidenceStatus;
use App\Exceptions\Billing\CheckoutException;
use App\Models\ManualCryptoPaymentClaim;
use App\Models\ManualCryptoReviewEvent;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Billing\PaymentCallbackIngestionService;
use Illuminate\Support\Facades\DB;

final readonly class ManualCryptoReviewService
{
    public function __construct(
        private AuditLogWriter $audit,
        private PaymentCallbackIngestionService $ingestion,
    ) {}

    public function start(ManualCryptoPaymentClaim $claim, User $reviewer): ManualCryptoPaymentClaim
    {
        $this->authorize($reviewer);

        return $this->transition($claim, $reviewer, ManualCryptoClaimState::UnderReview, 'review_started');
    }

    public function reject(ManualCryptoPaymentClaim $claim, User $reviewer, string $reason): ManualCryptoPaymentClaim
    {
        $this->authorize($reviewer);
        $this->requireReason($reason);

        return $this->transition($claim, $reviewer, ManualCryptoClaimState::Rejected, 'rejected', $reason);
    }

    public function reopen(ManualCryptoPaymentClaim $claim, User $reviewer, string $reason): ManualCryptoPaymentClaim
    {
        $this->authorize($reviewer);
        $this->requireReason($reason);
        if ($claim->state !== ManualCryptoClaimState::Rejected) {
            throw new CheckoutException('invalid_review_transition', 'Only a rejected claim may be reopened.', 409);
        }

        return $this->transition($claim, $reviewer, ManualCryptoClaimState::UnderReview, 'review_reopened', $reason);
    }

    public function approve(ManualCryptoPaymentClaim $claim, User $reviewer, string $reason): ManualCryptoPaymentClaim
    {
        $this->authorize($reviewer);
        $this->requireReason($reason);
        if ((string) $claim->user_id === (string) $reviewer->getKey()) {
            throw new CheckoutException('self_approval_forbidden', 'A submitter cannot approve their own claim.', 403);
        }

        $approved = DB::transaction(function () use ($claim, $reviewer, $reason): ManualCryptoPaymentClaim {
            $locked = ManualCryptoPaymentClaim::query()->with(['order', 'snapshot'])->lockForUpdate()->findOrFail($claim->getKey());
            if ($locked->state === ManualCryptoClaimState::Approved) {
                return $locked;
            }
            if (! in_array($locked->state, [ManualCryptoClaimState::Submitted, ManualCryptoClaimState::UnderReview], true)) {
                throw new CheckoutException('invalid_review_transition', 'The claim cannot be approved from its current state.', 409);
            }
            if ($locked->order->status === BillingOrderStatus::Paid) {
                $this->audit->write('manual_crypto.already_paid', (string) $reviewer->getKey(), $locked);
                throw new CheckoutException('order_already_paid', 'The order is already paid.');
            }
            if ($locked->order->expires_at?->isPast()) {
                $this->audit->write('manual_crypto.expired_claim', (string) $reviewer->getKey(), $locked);
                throw new CheckoutException('checkout_expired', 'The checkout has expired.');
            }
            if ($locked->submitted_amount_units !== ManualCryptoAmount::expectedUnits((int) $locked->snapshot->expected_amount_minor)) {
                throw new CheckoutException('crypto_amount_mismatch', 'The submitted amount does not match the order snapshot.', 409);
            }
            $eventId = 'manual_crypto_claim_'.$locked->getKey();
            $from = $locked->state;
            $locked->forceFill([
                'state' => ManualCryptoClaimState::Approved,
                'evidence_status' => ManualCryptoEvidenceStatus::ManuallyVerified,
                'reviewer_id' => $reviewer->getKey(), 'decision_reason' => $reason,
                'provider_event_id' => $eventId, 'reviewed_at' => now(), 'approved_at' => now(), 'rejected_at' => null,
            ])->save();
            $this->history($locked, $reviewer, 'approved', $from, ManualCryptoClaimState::Approved, $reason);
            $this->audit->write('manual_crypto.approved', (string) $reviewer->getKey(), $locked, newValues: [
                'state' => 'approved', 'evidence_status' => 'manually_verified', 'provider_event_id' => $eventId,
            ]);

            return $locked;
        });

        $raw = json_encode(['claim_id' => $approved->getKey(), 'event_id' => $approved->provider_event_id], JSON_THROW_ON_ERROR);
        $this->ingestion->ingest(new WebhookRequestData('manual_crypto', [], json_decode($raw, true, 512, JSON_THROW_ON_ERROR), $raw));

        return $approved->fresh(['snapshot', 'reviewEvents']);
    }

    private function transition(ManualCryptoPaymentClaim $claim, User $reviewer, ManualCryptoClaimState $to, string $event, ?string $reason = null): ManualCryptoPaymentClaim
    {
        return DB::transaction(function () use ($claim, $reviewer, $to, $event, $reason): ManualCryptoPaymentClaim {
            $locked = ManualCryptoPaymentClaim::query()->lockForUpdate()->findOrFail($claim->getKey());
            $allowed = match ($to) {
                ManualCryptoClaimState::UnderReview => in_array($locked->state, [ManualCryptoClaimState::Submitted, ManualCryptoClaimState::Rejected], true),
                ManualCryptoClaimState::Rejected => in_array($locked->state, [ManualCryptoClaimState::Submitted, ManualCryptoClaimState::UnderReview], true),
                default => false,
            };
            if (! $allowed) {
                throw new CheckoutException('invalid_review_transition', 'Invalid manual crypto review transition.', 409);
            }
            $from = $locked->state;
            $locked->forceFill([
                'state' => $to,
                'evidence_status' => $to === ManualCryptoClaimState::Rejected ? ManualCryptoEvidenceStatus::Rejected : ManualCryptoEvidenceStatus::Submitted,
                'reviewer_id' => $reviewer->getKey(), 'decision_reason' => $reason,
                'reviewed_at' => now(), 'rejected_at' => $to === ManualCryptoClaimState::Rejected ? now() : null,
            ])->save();
            $this->history($locked, $reviewer, $event, $from, $to, $reason);
            $this->audit->write('manual_crypto.'.$event, (string) $reviewer->getKey(), $locked, newValues: ['state' => $to->value]);

            return $locked->fresh(['snapshot', 'reviewEvents']);
        });
    }

    private function history(ManualCryptoPaymentClaim $claim, User $actor, string $event, ManualCryptoClaimState $from, ManualCryptoClaimState $to, ?string $reason): void
    {
        ManualCryptoReviewEvent::query()->create([
            'claim_id' => $claim->getKey(), 'actor_id' => $actor->getKey(), 'event' => $event,
            'from_state' => $from->value, 'to_state' => $to->value, 'reason' => $reason, 'created_at' => now(),
        ]);
    }

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new CheckoutException('review_reason_required', 'A review reason is required.', 422);
        }
    }

    private function authorize(User $reviewer): void
    {
        if (! $reviewer->isPlatformAdmin()) {
            throw new CheckoutException('billing_reviewer_required', 'Billing reviewer permission is required.', 403);
        }
    }
}
