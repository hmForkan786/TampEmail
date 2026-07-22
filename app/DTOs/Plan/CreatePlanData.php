<?php

declare(strict_types=1);

namespace App\DTOs\Plan;

/**
 * Immutable input data for creating a new plan record.
 */
final readonly class CreatePlanData
{
    /**
     * @param string $slug Unique plan business identifier.
     * @param string $name Human-readable plan name.
     * @param string|null $description Optional plan description.
     * @param string $priceMonthly Monthly price amount.
     * @param string $priceYearly Yearly price amount.
     * @param string $currency Three-letter currency code.
     * @param bool $isFree Whether the plan is free.
     * @param bool $isActive Whether the plan is active.
     * @param int $displayOrder Sorting order for plan display.
     * @param array<string, mixed>|null $metadata Optional additional plan metadata.
     */
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $description,
        public string $priceMonthly,
        public string $priceYearly,
        public string $currency,
        public bool $isFree,
        public bool $isActive,
        public int $displayOrder,
        public ?array $metadata,
    ) {}

    /**
     * Create a DTO instance from an array payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            slug: (string) $data['slug'],
            name: (string) $data['name'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            priceMonthly: (string) $data['price_monthly'],
            priceYearly: (string) $data['price_yearly'],
            currency: array_key_exists('currency', $data)
                ? (string) $data['currency']
                : 'USD',
            isFree: array_key_exists('is_free', $data)
                ? (bool) $data['is_free']
                : false,
            isActive: array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : true,
            displayOrder: array_key_exists('display_order', $data)
                ? (int) $data['display_order']
                : 0,
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
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'price_monthly' => $this->priceMonthly,
            'price_yearly' => $this->priceYearly,
            'currency' => $this->currency,
            'is_free' => $this->isFree,
            'is_active' => $this->isActive,
            'display_order' => $this->displayOrder,
            'metadata' => $this->metadata,
        ];
    }
}
