<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InboundFailures\Pages;

use App\Filament\Admin\Resources\InboundFailures\InboundFailureResource;
use Filament\Resources\Pages\ViewRecord;

class ViewInboundFailure extends ViewRecord
{
    protected static string $resource = InboundFailureResource::class;
    protected function getHeaderActions(): array { return []; }
}
