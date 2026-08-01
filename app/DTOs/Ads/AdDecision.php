<?php

declare(strict_types=1);

namespace App\DTOs\Ads;

/**
 * Result of a placement decision. Null campaign means "show nothing".
 */
final readonly class AdDecision
{
    public function __construct(
        public string $placementKey,
        public bool $show,
        public ?string $reason = null,
        public ?string $campaignId = null,
        public ?string $placementId = null,
        public ?string $provider = null,
        public ?string $purpose = null,
        public ?string $impressionId = null,
        public ?AdRenderPayload $render = null,
    ) {}

    public static function empty(string $placementKey, string $reason): self
    {
        return new self(
            placementKey: $placementKey,
            show: false,
            reason: $reason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'placement' => $this->placementKey,
            'show' => $this->show,
            'reason' => $this->reason,
            'campaign_id' => $this->campaignId,
            'placement_id' => $this->placementId,
            'provider' => $this->provider,
            'purpose' => $this->purpose,
            'impression_id' => $this->impressionId,
            'render' => $this->render?->toArray(),
        ];
    }
}
