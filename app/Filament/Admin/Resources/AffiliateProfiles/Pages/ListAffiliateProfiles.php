<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateProfiles\Pages;

use App\Filament\Admin\Resources\AffiliateProfiles\AffiliateProfileResource;
use Filament\Resources\Pages\ListRecords;

final class ListAffiliateProfiles extends ListRecords
{
    protected static string $resource = AffiliateProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
