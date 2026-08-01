<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateCommissionPlans\Pages;

use App\Filament\Admin\Resources\AffiliateCommissionPlans\AffiliateCommissionPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAffiliateCommissionPlans extends ListRecords
{
    protected static string $resource = AffiliateCommissionPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
