<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InboundHolds\Pages;

use App\Filament\Admin\Resources\InboundHolds\InboundHoldResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInboundHolds extends ListRecords
{
    protected static string $resource = InboundHoldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
