<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AdPlacements\Pages;

use App\Filament\Admin\Resources\AdPlacements\AdPlacementResource;
use Filament\Resources\Pages\EditRecord;

final class EditAdPlacement extends EditRecord
{
    protected static string $resource = AdPlacementResource::class;
}
