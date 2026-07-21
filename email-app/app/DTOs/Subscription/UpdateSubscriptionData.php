<?php

declare(strict_types=1);

namespace App\DTOs\Subscription;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Immutable input data for partially updating a subscription record.
 */
final readonly class UpdateSubscriptionData
{
    /**
     * @param string|null $userId Owning user UUID.
     * @param string|null $planId Associated billing plan UUID.
     * @param SubscriptionStatus|null $status Subscription lifecycle status.
     * @param BillingCycle|null $billingCycle Billing interval for the subscription.
     * @param CarbonInterface|null $startsAt Timestamp when the subscription starts.
     * @param CarbonInterface|null $trialEndsAt Optional trial end timestamp.
     * @param CarbonInterface|null $endsAt Optional subscription end timestamp.
     * @param CarbonInterface|null $cancelledAt Optional cancellation timestamp.
     * @param bool|null $autoRenew Whether the subscription renews automatically.
     * @param string|null $price Subscription price amount.
     * @param string|null $currency Three-letter currency code.
     * @param array<string, mixed>|null $metadata Optional additional subscription metadata.
     */
    public function __construct(
        public ?string $userId,
        public ?string $planId,
        public ?SubscriptionStatus $status,
        public ?BillingCycle $billingCycle,
        public ?CarbonInterface $startsAt,
        public ?CarbonInterface $trialEndsAt,
        public ?CarbonInterface $endsAt,
        public ?CarbonInterface $cancelledAt,
        public ?bool $autoRenew,
        public ?string $price,
        public ?string $currency,
        public ?array $metadata,
    ) {}

    /**
     * Create a DTO instance from an array payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $status = null;

        if (array_key_exists('status', $data)) {
            $status = $data['status'] instanceof SubscriptionStatus
                ? $data['status']
                : SubscriptionStatus::from((string) $data['status']);
        }

        $billingCycle = null;

        if (array_key_exists('billing_cycle', $data)) {
            $billingCycle = $data['billing_cycle'] instanceof BillingCycle
                ? $data['billing_cycle']
                : BillingCycle::from((string) $data['billing_cycle']);
        }

        $startsAt = null;

        if (array_key_exists('starts_at', $data)) {
            $startsAt = $data['starts_at'] === null
                ? null
                : ($data['starts_at'] instanceof CarbonInterface
                    ? $data['starts_at']
                    : Carbon::parse($data['starts_at']));
        }

        $trialEndsAt = null;

        if (array_key_exists('trial_ends_at', $data)) {
            $trialEndsAt = $data['trial_ends_at'] === null
                ? null
                : ($data['trial_ends_at'] instanceof CarbonInterface
                    ? $data['trial_ends_at']
                    : Carbon::parse($data['trial_ends_at']));
        }

        $endsAt = null;

        if (array_key_exists('ends_at', $data)) {
            $endsAt = $data['ends_at'] === null
                ? null
                : ($data['ends_at'] instanceof CarbonInterface
                    ? $data['ends_at']
                    : Carbon::parse($data['ends_at']));
        }

        $cancelledAt = null;

        if (array_key_exists('cancelled_at', $data)) {
            $cancelledAt = $data['cancelled_at'] === null
                ? null
                : ($data['cancelled_at'] instanceof CarbonInterface
                    ? $data['cancelled_at']
                    : Carbon::parse($data['cancelled_at']));
        }

        return new self(
            userId: array_key_exists('user_id', $data)
                ? ($data['user_id'] !== null ? (string) $data['user_id'] : null)
                : null,
            planId: array_key_exists('plan_id', $data)
                ? ($data['plan_id'] !== null ? (string) $data['plan_id'] : null)
                : null,
            status: $status,
            billingCycle: $billingCycle,
            startsAt: $startsAt,
            trialEndsAt: $trialEndsAt,
            endsAt: $endsAt,
            cancelledAt: $cancelledAt,
            autoRenew: array_key_exists('auto_renew', $data)
                ? ($data['auto_renew'] !== null ? (bool) $data['auto_renew'] : null)
                : null,
            price: array_key_exists('price', $data)
                ? ($data['price'] !== null ? (string) $data['price'] : null)
                : null,
            currency: array_key_exists('currency', $data)
                ? ($data['currency'] !== null ? (string) $data['currency'] : null)
                : null,
            metadata: array_key_exists('metadata', $data)
                ? ($data['metadata'] !== null ? (array) $data['metadata'] : null)
                : null,
        );
    }

    /**
     * Convert the DTO to model-fillable attributes for update.
     *
     * Only non-null properties are included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $attributes = [];

        if ($this->userId !== null) {
            $attributes['user_id'] = $this->userId;
        }

        if ($this->planId !== null) {
            $attributes['plan_id'] = $this->planId;
        }

        if ($this->status !== null) {
            $attributes['status'] = $this->status->value;
        }

        if ($this->billingCycle !== null) {
            $attributes['billing_cycle'] = $this->billingCycle->value;
        }

        if ($this->startsAt !== null) {
            $attributes['starts_at'] = $this->startsAt;
        }

        if ($this->trialEndsAt !== null) {
            $attributes['trial_ends_at'] = $this->trialEndsAt;
        }

        if ($this->endsAt !== null) {
            $attributes['ends_at'] = $this->endsAt;
        }

        if ($this->cancelledAt !== null) {
            $attributes['cancelled_at'] = $this->cancelledAt;
        }

        if ($this->autoRenew !== null) {
            $attributes['auto_renew'] = $this->autoRenew;
        }

        if ($this->price !== null) {
            $attributes['price'] = $this->price;
        }

        if ($this->currency !== null) {
            $attributes['currency'] = $this->currency;
        }

        if ($this->metadata !== null) {
            $attributes['metadata'] = $this->metadata;
        }

        return $attributes;
    }
}
