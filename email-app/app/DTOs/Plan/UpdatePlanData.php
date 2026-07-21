<?php

declare(strict_types=1);

namespace App\DTOs\Plan;

/**
 * Immutable input data for partially updating a plan record.
 */
final readonly class UpdatePlanData
{
    /**
     * @param string|null $slug Unique plan business identifier.
     * @param string|null $name Human-readable plan name.
     * @param string|null $description Optional plan description.
     * @param string|null $priceMonthly Monthly price amount.
     * @param string|null $priceYearly Yearly price amount.
     * @param string|null $currency Three-letter currency code.
     * @param bool|null $isFree Whether the plan is free.
     * @param bool|null $isActive Whether the plan is active.
     * @param int|null $displayOrder Sorting order for plan display.
     * @param array<string, mixed>|null $metadata Optional additional plan metadata.
     */
    public function __construct(
        public ?string $slug,
        public ?string $name,
        public ?string $description,
        public ?string $priceMonthly,
        public ?string $priceYearly,
        public ?string $currency,
        public ?bool $isFree,
        public ?bool $isActive,
        public ?int $displayOrder,
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
            slug: array_key_exists('slug', $data)
                ? ($data['slug'] !== null ? (string) $data['slug'] : null)
                : null,
            name: array_key_exists('name', $data)
                ? ($data['name'] !== null ? (string) $data['name'] : null)
                : null,
            description: array_key_exists('description', $data)
                ? ($data['description'] !== null ? (string) $data['description'] : null)
                : null,
            priceMonthly: array_key_exists('price_monthly', $data)
                ? ($data['price_monthly'] !== null ? (string) $data['price_monthly'] : null)
                : null,
            priceYearly: array_key_exists('price_yearly', $data)
                ? ($data['price_yearly'] !== null ? (string) $data['price_yearly'] : null)
                : null,
            currency: array_key_exists('currency', $data)
                ? ($data['currency'] !== null ? (string) $data['currency'] : null)
                : null,
            isFree: array_key_exists('is_free', $data)
                ? ($data['is_free'] !== null ? (bool) $data['is_free'] : null)
                : null,
            isActive: array_key_exists('is_active', $data)
                ? ($data['is_active'] !== null ? (bool) $data['is_active'] : null)
                : null,
            displayOrder: array_key_exists('display_order', $data)
                ? ($data['display_order'] !== null ? (int) $data['display_order'] : null)
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

        if ($this->slug !== null) {
            $attributes['slug'] = $this->slug;
        }

        if ($this->name !== null) {
            $attributes['name'] = $this->name;
        }

        if ($this->description !== null) {
            $attributes['description'] = $this->description;
        }

        if ($this->priceMonthly !== null) {
            $attributes['price_monthly'] = $this->priceMonthly;
        }

        if ($this->priceYearly !== null) {
            $attributes['price_yearly'] = $this->priceYearly;
        }

        if ($this->currency !== null) {
            $attributes['currency'] = $this->currency;
        }

        if ($this->isFree !== null) {
            $attributes['is_free'] = $this->isFree;
        }

        if ($this->isActive !== null) {
            $attributes['is_active'] = $this->isActive;
        }

        if ($this->displayOrder !== null) {
            $attributes['display_order'] = $this->displayOrder;
        }

        if ($this->metadata !== null) {
            $attributes['metadata'] = $this->metadata;
        }

        return $attributes;
    }
}
