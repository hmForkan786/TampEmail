<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ApiKeys\Pages;

use App\Filament\Admin\Resources\ApiKeys\ApiKeyResource;
use Filament\Resources\Pages\ListRecords;

class ListApiKeys extends ListRecords
{
    protected static string $resource = ApiKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
