<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\QuarantinedAttachments\Pages;

use App\Filament\Admin\Resources\QuarantinedAttachments\QuarantinedAttachmentResource;
use Filament\Resources\Pages\ListRecords;

class ListQuarantinedAttachments extends ListRecords
{
    protected static string $resource = QuarantinedAttachmentResource::class;
}
