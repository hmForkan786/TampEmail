<?php

declare(strict_types=1);

namespace App\DTOs\Feature;

use App\Enums\ValueType;

/**
 * Immutable input data for creating a new feature record.
 */
final readonly class CreateFeatureData
{
    /**
     * @param string $key Stable machine-readable feature identifier.
     * @param string $name Human-readable feature name.
     * @param string|null $description Optional feature description.
     * @param string|null $category Optional feature category.
     * @param ValueType $valueType How plan values for this feature are interpreted.
     * @param array<string, mixed>|null $defaultValue Optional default feature value payload.
     * @param bool $isActive Whether the feature is active.
     * @param int $displayOrder Sorting order for feature display.
     * @param array<string, mixed>|null $metadata Optional additional feature metadata.
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $description,
        public ?string $category,
        public ValueType $valueType,
        public ?array $defaultValue,
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
        $valueType = $data['value_type'] ?? ValueType::Boolean;

        if (! $valueType instanceof ValueType) {
            $valueType = ValueType::from((string) $valueType);
        }

        return new self(
            key: (string) $data['key'],
            name: (string) $data['name'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            category: isset($data['category']) ? (string) $data['category'] : null,
            valueType: $valueType,
            defaultValue: isset($data['default_value']) ? (array) $data['default_value'] : null,
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
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'value_type' => $this->valueType->value,
            'default_value' => $this->defaultValue,
            'is_active' => $this->isActive,
            'display_order' => $this->displayOrder,
            'metadata' => $this->metadata,
        ];
    }
}
