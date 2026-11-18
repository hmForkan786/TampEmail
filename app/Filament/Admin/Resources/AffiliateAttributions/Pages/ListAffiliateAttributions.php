<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateAttributions\Pages;

use App\Filament\Admin\Resources\AffiliateAttributions\AffiliateAttributionResource;
use Filament\Resources\Pages\ListRecords;

final class ListAffiliateAttributions extends ListRecords
{
    protected static string $resource = AffiliateAttributionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
