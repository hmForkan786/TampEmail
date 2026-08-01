<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateWithdrawals\Pages;

use App\Filament\Admin\Resources\AffiliateWithdrawals\AffiliateWithdrawalResource;
use Filament\Resources\Pages\ListRecords;

final class ListAffiliateWithdrawals extends ListRecords
{
    protected static string $resource = AffiliateWithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
