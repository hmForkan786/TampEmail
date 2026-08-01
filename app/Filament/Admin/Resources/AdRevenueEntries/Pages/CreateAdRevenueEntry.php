<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AdRevenueEntries\Pages;

use App\Filament\Admin\Resources\AdRevenueEntries\AdRevenueEntryResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateAdRevenueEntry extends CreateRecord
{
    protected static string $resource = AdRevenueEntryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data['recorded_by'] = auth()->id();

        return parent::handleRecordCreation($data);
    }
}
