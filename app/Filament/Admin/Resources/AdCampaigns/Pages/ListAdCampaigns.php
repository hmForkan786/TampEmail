<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AdCampaigns\Pages;

use App\Filament\Admin\Resources\AdCampaigns\AdCampaignResource;
use Filament\Resources\Pages\ListRecords;

final class ListAdCampaigns extends ListRecords
{
    protected static string $resource = AdCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
