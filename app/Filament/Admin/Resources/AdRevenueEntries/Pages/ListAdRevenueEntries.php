<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AdRevenueEntries\Pages;

use App\Filament\Admin\Resources\AdRevenueEntries\AdRevenueEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAdRevenueEntries extends ListRecords
{
    protected static string $resource = AdRevenueEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
