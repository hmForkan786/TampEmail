<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateCommissionEntries\Pages;

use App\Filament\Admin\Resources\AffiliateCommissionEntries\AffiliateCommissionEntryResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewAffiliateCommissionEntry extends ViewRecord
{
    protected static string $resource = AffiliateCommissionEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
