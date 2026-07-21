<?php

declare(strict_types=1);

namespace App\DTOs\Subscription;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Immutable input data for creating a new subscription record.
 */
final readonly class CreateSubscriptionData
{
    /**
     * @param string $userId Owning user UUID.
     * @param string $planId Associated billing plan UUID.
     * @param SubscriptionStatus $status Subscription lifecycle status.
     * @param BillingCycle $billingCycle Billing interval for the subscription.
     * @param CarbonInterface $startsAt Timestamp when the subscription starts.
     * @param CarbonInterface|null $trialEndsAt Optional trial end timestamp.
     * @param CarbonInterface|null $endsAt Optional subscription end timestamp.
     * @param CarbonInterface|null $cancelledAt Optional cancellation timestamp.
     * @param bool $autoRenew Whether the subscription renews automatically.
     * @param string $price Subscription price amount.
     * @param string $currency Three-letter currency code.
     * @param array<string, mixed>|null $metadata Optional additional subscription metadata.
     */
    public function __construct(
        public string $userId,
        public string $planId,
        public SubscriptionStatus $status,
        public BillingCycle $billingCycle,
        public CarbonInterface $startsAt,
        public ?CarbonInterface $trialEndsAt,
        public ?CarbonInterface $endsAt,
        public ?CarbonInterface $cancelledAt,
        public bool $autoRenew,
        public string $price,
        public string $currency,
        public ?array $metadata,
    ) {}

    /**
     * Create a DTO instance from an array payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $status = $data['status'];

        if (! $status instanceof SubscriptionStatus) {
            $status = SubscriptionStatus::from((string) $status);
        }

        $billingCycle = $data['billing_cycle'];

        if (! $billingCycle instanceof BillingCycle) {
            $billingCycle = BillingCycle::from((string) $billingCycle);
        }

        $startsAt = $data['starts_at'];

        if (! $startsAt instanceof CarbonInterface) {
            $startsAt = Carbon::parse($startsAt);
        }

        $trialEndsAt = null;

        if (array_key_exists('trial_ends_at', $data) && $data['trial_ends_at'] !== null) {
            $trialEndsAt = $data['trial_ends_at'] instanceof CarbonInterface
                ? $data['trial_ends_at']
                : Carbon::parse($data['trial_ends_at']);
        }

        $endsAt = null;

        if (array_key_exists('ends_at', $data) && $data['ends_at'] !== null) {
            $endsAt = $data['ends_at'] instanceof CarbonInterface
                ? $data['ends_at']
                : Carbon::parse($data['ends_at']);
        }

        $cancelledAt = null;

        if (array_key_exists('cancelled_at', $data) && $data['cancelled_at'] !== null) {
            $cancelledAt = $data['cancelled_at'] instanceof CarbonInterface
                ? $data['cancelled_at']
                : Carbon::parse($data['cancelled_at']);
        }

        return new self(
            userId: (string) $data['user_id'],
            planId: (string) $data['plan_id'],
            status: $status,
            billingCycle: $billingCycle,
            startsAt: $startsAt,
            trialEndsAt: $trialEndsAt,
            endsAt: $endsAt,
            cancelledAt: $cancelledAt,
            autoRenew: array_key_exists('auto_renew', $data)
                ? (bool) $data['auto_renew']
                : true,
            price: (string) $data['price'],
            currency: (string) $data['currency'],
            metadata: isset($data['metadata']) ? (array) $data['metadata'] : null,
        );
    }

    /**
     * Convert the DTO to model-fillable attributes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'plan_id' => $this->planId,
            'status' => $this->status->value,
            'billing_cycle' => $this->billingCycle->value,
            'starts_at' => $this->startsAt,
            'trial_ends_at' => $this->trialEndsAt,
            'ends_at' => $this->endsAt,
            'cancelled_at' => $this->cancelledAt,
            'auto_renew' => $this->autoRenew,
            'price' => $this->price,
            'currency' => $this->currency,
            'metadata' => $this->metadata,
        ];
    }
}
