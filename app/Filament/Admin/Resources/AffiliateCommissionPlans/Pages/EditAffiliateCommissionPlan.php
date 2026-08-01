<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateCommissionPlans\Pages;

use App\Filament\Admin\Resources\AffiliateCommissionPlans\AffiliateCommissionPlanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditAffiliateCommissionPlan extends EditRecord
{
    protected static string $resource = AffiliateCommissionPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
