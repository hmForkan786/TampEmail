<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InboundHolds\Pages;

use App\Actions\Inbound\ReleaseInboundHoldAction;
use App\Filament\Admin\Resources\InboundHolds\InboundHoldResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewInboundHold extends ViewRecord
{
    protected static string $resource = InboundHoldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('release')
                ->label('Release hold')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getRecord()->isActive())
                ->action(function (): void {
                    $hold = app(ReleaseInboundHoldAction::class)->execute(
                        (string) $this->getRecord()->getKey(),
                        (string) auth()->id(),
                    );
                    $this->record = $hold->fresh(['heldBy', 'releasedBy']);
                    Notification::make()->title('Inbound hold released')->success()->send();
                }),
        ];
    }
}
