<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InboundFailures\Pages;

use App\Filament\Admin\Resources\InboundFailures\InboundFailureResource;
use Filament\Resources\Pages\ListRecords;

class ListInboundFailures extends ListRecords
{
    protected static string $resource = InboundFailureResource::class;
    protected function getHeaderActions(): array { return []; }
}
