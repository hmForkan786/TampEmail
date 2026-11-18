<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AdPlacements\Pages;

use App\Filament\Admin\Resources\AdPlacements\AdPlacementResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateAdPlacement extends CreateRecord
{
    protected static string $resource = AdPlacementResource::class;
}
