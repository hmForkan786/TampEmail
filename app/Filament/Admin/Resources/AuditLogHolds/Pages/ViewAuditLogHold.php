<?php
declare(strict_types=1);
namespace App\Filament\Admin\Resources\AuditLogHolds\Pages;
use App\Actions\AuditLog\ReleaseAuditLogHoldAction;
use App\Filament\Admin\Resources\AuditLogHolds\AuditLogHoldResource;
use App\Models\AuditLogHold;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
class ViewAuditLogHold extends ViewRecord
{
    protected static string $resource = AuditLogHoldResource::class;
    protected function getHeaderActions(): array { return [Action::make('release')->label('Release hold')->color('danger')->requiresConfirmation()->visible(fn (): bool => $this->getRecord()->isActive())->action(function (): void { $hold = app(ReleaseAuditLogHoldAction::class)->execute((string)$this->getRecord()->getKey(), (string)auth()->id()); $this->record = $hold->fresh(['auditLog','heldBy','releasedBy']); Notification::make()->title('Audit hold released')->success()->send(); })]; }
}
