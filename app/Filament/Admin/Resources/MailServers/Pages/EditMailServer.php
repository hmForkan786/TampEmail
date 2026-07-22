<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MailServers\Pages;

use App\Actions\MailServer\UpdateMailServerAction;
use App\DTOs\MailServer\UpdateMailServerData;
use App\DTOs\MailServer\MailServerMutationContext;
use App\Filament\Admin\Resources\MailServers\MailServerResource;
use App\Models\MailServer;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMailServer extends EditRecord
{
    protected static string $resource = MailServerResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['port'] = $data['metadata']['port'] ?? null;
        unset($data['metadata']);
        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $metadata = $record->metadata ?? [];
        if (filled($data['port'] ?? null)) {
            $metadata['port'] = (int) $data['port'];
        } else {
            unset($metadata['port']);
        }
        $metadata = $metadata === [] ? null : $metadata;
        unset($data['port']);
        $data['metadata'] = $metadata;
        $actor = auth()->user();
        return app(UpdateMailServerAction::class)->execute($record, UpdateMailServerData::fromArray($data), new MailServerMutationContext((string) $actor->getKey(), 'filament'));
    }
}
