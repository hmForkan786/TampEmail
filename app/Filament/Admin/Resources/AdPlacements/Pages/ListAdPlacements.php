<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AdPlacements\Pages;

use App\Filament\Admin\Resources\AdPlacements\AdPlacementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAdPlacements extends ListRecords
{
    protected static string $resource = AdPlacementResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
