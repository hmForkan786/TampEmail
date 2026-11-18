<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateCommissionPlans\Pages;

use App\Filament\Admin\Resources\AffiliateCommissionPlans\AffiliateCommissionPlanResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateAffiliateCommissionPlan extends CreateRecord
{
    protected static string $resource = AffiliateCommissionPlanResource::class;
}
