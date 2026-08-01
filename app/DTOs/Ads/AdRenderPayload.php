<?php

declare(strict_types=1);

namespace App\DTOs\Ads;

/**
 * Provider-neutral render payload returned by the decision engine.
 *
 * Views must consume this DTO only — never call providers directly.
 *
 * @phpstan-type RenderPayload array{
 *     type: string,
 *     safe: bool,
 *     markup?: string|null,
 *     attributes?: array<string, scalar|null>,
 *     click_url?: string|null,
 *     image_url?: string|null,
 *     headline?: string|null,
 *     body?: string|null,
 *     cta_label?: string|null,
 *     cta_url?: string|null,
 *     publisher_id?: string|null,
 *     slot_id?: string|null,
 *     responsive?: bool|null,
 *     promotion_kind?: string|null
 * }
 */
final readonly class AdRenderPayload
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $type,
        public bool $safe,
        public array $data = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(['type' => $this->type, 'safe' => $this->safe], $this->data);
    }
}
