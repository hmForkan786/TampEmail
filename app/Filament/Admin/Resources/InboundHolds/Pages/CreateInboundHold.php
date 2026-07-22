<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InboundHolds\Pages;

use App\Actions\Inbound\CreateInboundHoldAction;
use App\DTOs\Inbound\CreateInboundHoldData;
use App\Filament\Admin\Resources\InboundHolds\InboundHoldResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateInboundHold extends CreateRecord
{
    protected static string $resource = InboundHoldResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        try {
            return app(CreateInboundHoldAction::class)->execute(new CreateInboundHoldData(
                (string) $data['target_type'],
                (string) $data['target_id'],
                (string) $actor->getKey(),
                (string) $data['reason'],
                isset($data['held_until']) && $data['held_until'] !== null
                    ? \Carbon\Carbon::parse($data['held_until'])
                    : null,
            ));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'data.target_id' => $exception->getMessage(),
            ]);
        }
    }
}
