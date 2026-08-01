<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateConversions\Pages;

use App\Filament\Admin\Resources\AffiliateConversions\AffiliateConversionResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewAffiliateConversion extends ViewRecord
{
    protected static string $resource = AffiliateConversionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
