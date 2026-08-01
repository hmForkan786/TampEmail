<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateCommissionEntries\Pages;

use App\Filament\Admin\Resources\AffiliateCommissionEntries\AffiliateCommissionEntryResource;
use Filament\Resources\Pages\ListRecords;

final class ListAffiliateCommissionEntries extends ListRecords
{
    protected static string $resource = AffiliateCommissionEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
