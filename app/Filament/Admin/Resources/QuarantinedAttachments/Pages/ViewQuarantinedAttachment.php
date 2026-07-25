<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\QuarantinedAttachments\Pages;

use App\Actions\Attachment\PermanentlyDeleteQuarantinedAttachmentAction;
use App\Actions\Attachment\RescanFailedAttachmentAction;
use App\Filament\Admin\Resources\QuarantinedAttachments\QuarantinedAttachmentResource;
use App\Models\Attachment;
use App\Models\User;
use App\Policies\AttachmentPolicy;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewQuarantinedAttachment extends ViewRecord
{
    protected static string $resource = QuarantinedAttachmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rescan')
                ->label('Rescan')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(function (): bool {
                    $actor = auth()->user();
                    $record = $this->getRecord();

                    return $actor instanceof User
                        && $record instanceof Attachment
                        && app(AttachmentPolicy::class)->rescan($actor, $record);
                })
                ->action(function (): void {
                    $actor = auth()->user();
                    $record = $this->getRecord();
                    if (! $actor instanceof User || ! $record instanceof Attachment) {
                        return;
                    }

                    $updated = app(RescanFailedAttachmentAction::class)->execute($actor, $record);
                    $this->record = $updated->fresh() ?? $updated;
                    Notification::make()->title('Rescan queued')->success()->send();
                }),
            Action::make('permanentlyDelete')
                ->label('Permanently delete')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(function (): bool {
                    $actor = auth()->user();
                    $record = $this->getRecord();

                    return $actor instanceof User
                        && $record instanceof Attachment
                        && app(AttachmentPolicy::class)->permanentlyDelete($actor, $record);
                })
                ->action(function (): void {
                    $actor = auth()->user();
                    $record = $this->getRecord();
                    if (! $actor instanceof User || ! $record instanceof Attachment) {
                        return;
                    }

                    app(PermanentlyDeleteQuarantinedAttachmentAction::class)->execute($actor, $record);
                    Notification::make()->title('Attachment permanently deleted')->success()->send();
                    $this->redirect(QuarantinedAttachmentResource::getUrl('index'));
                }),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();
        $status = $record instanceof Attachment ? ($record->scan_status?->value ?? 'quarantined') : 'quarantined';

        return 'Quarantined attachment ('.$status.')';
    }
}
