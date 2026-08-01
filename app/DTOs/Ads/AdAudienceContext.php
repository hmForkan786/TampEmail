<?php

declare(strict_types=1);

namespace App\DTOs\Ads;

use App\Enums\AdDevice;
use App\Models\User;

/** Audience context for targeting and commercial checks. */
final readonly class AdAudienceContext
{
    public function __construct(
        public ?User $user = null,
        public ?string $country = null,
        public ?AdDevice $device = null,
        public ?string $language = null,
        public ?string $theme = null,
        public ?string $sessionHash = null,
        public ?string $ipHash = null,
        public bool $isAuthenticated = false,
        public bool $adsVisible = false,
        public bool $isPremium = false,
    ) {}

    public static function guest(
        ?string $country = null,
        ?AdDevice $device = null,
        ?string $language = null,
        ?string $theme = null,
        ?string $sessionHash = null,
        ?string $ipHash = null,
    ): self {
        return new self(
            user: null,
            country: $country,
            device: $device,
            language: $language,
            theme: $theme,
            sessionHash: $sessionHash,
            ipHash: $ipHash,
            isAuthenticated: false,
            adsVisible: true,
            isPremium: false,
        );
    }
}
