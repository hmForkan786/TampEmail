<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CommercialPlans\Pages;

use App\Filament\Admin\Resources\CommercialPlans\CommercialPlanResource;
use Filament\Resources\Pages\ListRecords;

final class ListCommercialPlans extends ListRecords
{
    protected static string $resource = CommercialPlanResource::class;
}
