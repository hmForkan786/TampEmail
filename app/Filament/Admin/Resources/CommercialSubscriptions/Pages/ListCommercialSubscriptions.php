<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CommercialSubscriptions\Pages;

use App\Filament\Admin\Resources\CommercialSubscriptions\CommercialSubscriptionResource;
use Filament\Resources\Pages\ListRecords;

final class ListCommercialSubscriptions extends ListRecords
{
    protected static string $resource = CommercialSubscriptionResource::class;
}
