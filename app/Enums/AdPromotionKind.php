<?php

declare(strict_types=1);

namespace App\Enums;

enum AdPromotionKind: string
{
    case Upgrade = 'upgrade';
    case Affiliate = 'affiliate';
    case FeatureAnnouncement = 'feature_announcement';
    case Maintenance = 'maintenance';
    case Coupon = 'coupon';
    case Seasonal = 'seasonal';
    case Blog = 'blog';
    case Partner = 'partner';
    case Generic = 'generic';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Upgrade->value => 'Free → Premium upgrade',
            self::Affiliate->value => 'Affiliate',
            self::FeatureAnnouncement->value => 'New feature announcement',
            self::Maintenance->value => 'Maintenance notice',
            self::Coupon->value => 'Coupon campaign',
            self::Seasonal->value => 'Seasonal offer',
            self::Blog->value => 'Blog promotion',
            self::Partner->value => 'Partner promotion',
            self::Generic->value => 'Generic promotion',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value] ?? $this->value;
    }
}
