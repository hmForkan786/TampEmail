<?php

declare(strict_types=1);

namespace App\DTOs\Feature;

use App\Enums\ValueType;

/**
 * Immutable input data for partially updating a feature record.
 */
final readonly class UpdateFeatureData
{
    /**
     * @param string|null $key Stable machine-readable feature identifier.
     * @param string|null $name Human-readable feature name.
     * @param string|null $description Optional feature description.
     * @param string|null $category Optional feature category.
     * @param ValueType|null $valueType How plan values for this feature are interpreted.
     * @param array<string, mixed>|null $defaultValue Optional default feature value payload.
     * @param bool|null $isActive Whether the feature is active.
     * @param int|null $displayOrder Sorting order for feature display.
     * @param array<string, mixed>|null $metadata Optional additional feature metadata.
     */
    public function __construct(
        public ?string $key,
        public ?string $name,
        public ?string $description,
        public ?string $category,
        public ?ValueType $valueType,
        public ?array $defaultValue,
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
        $valueType = null;

        if (array_key_exists('value_type', $data) && $data['value_type'] !== null) {
            $valueType = $data['value_type'] instanceof ValueType
                ? $data['value_type']
                : ValueType::from((string) $data['value_type']);
        }

        return new self(
            key: array_key_exists('key', $data)
                ? ($data['key'] !== null ? (string) $data['key'] : null)
                : null,
            name: array_key_exists('name', $data)
                ? ($data['name'] !== null ? (string) $data['name'] : null)
                : null,
            description: array_key_exists('description', $data)
                ? ($data['description'] !== null ? (string) $data['description'] : null)
                : null,
            category: array_key_exists('category', $data)
                ? ($data['category'] !== null ? (string) $data['category'] : null)
                : null,
            valueType: $valueType,
            defaultValue: array_key_exists('default_value', $data)
                ? ($data['default_value'] !== null ? (array) $data['default_value'] : null)
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

        if ($this->key !== null) {
            $attributes['key'] = $this->key;
        }

        if ($this->name !== null) {
            $attributes['name'] = $this->name;
        }

        if ($this->description !== null) {
            $attributes['description'] = $this->description;
        }

        if ($this->category !== null) {
            $attributes['category'] = $this->category;
        }

        if ($this->valueType !== null) {
            $attributes['value_type'] = $this->valueType->value;
        }

        if ($this->defaultValue !== null) {
            $attributes['default_value'] = $this->defaultValue;
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
