<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MailServers\Pages;

use App\Actions\MailServer\CreateMailServerAction;
use App\DTOs\MailServer\CreateMailServerData;
use App\DTOs\MailServer\MailServerMutationContext;
use App\Filament\Admin\Resources\MailServers\MailServerResource;
use App\Models\MailServer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMailServer extends CreateRecord
{
    protected static string $resource = MailServerResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $metadata = filled($data['port'] ?? null) ? ['port' => (int) $data['port']] : null;
        unset($data['port']);
        $data['metadata'] = $metadata;
        $actor = auth()->user();
        return app(CreateMailServerAction::class)->execute(CreateMailServerData::fromArray($data), new MailServerMutationContext((string) $actor->getKey(), 'filament'));
    }
}
