<?php

declare(strict_types=1);

namespace App\Enums;

enum AdCampaignStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Expired = 'expired';
    case BudgetReached = 'budget_reached';
    case Archived = 'archived';

    public function isSelectable(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Draft->value => 'Draft',
            self::Active->value => 'Active',
            self::Paused->value => 'Paused',
            self::Expired->value => 'Expired',
            self::BudgetReached->value => 'Budget reached',
            self::Archived->value => 'Archived',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
