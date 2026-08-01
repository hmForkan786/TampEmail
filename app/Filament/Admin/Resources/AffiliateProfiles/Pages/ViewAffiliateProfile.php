<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateProfiles\Pages;

use App\Enums\AffiliateProfileStatus;
use App\Exceptions\Affiliates\AffiliateException;
use App\Filament\Admin\Resources\AffiliateProfiles\AffiliateProfileResource;
use App\Models\AffiliateProfile;
use App\Models\User;
use App\Services\Affiliates\AffiliateRegistrationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewAffiliateProfile extends ViewRecord
{
    protected static string $resource = AffiliateProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('approve')
                ->label('Approve')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record()->status !== AffiliateProfileStatus::Active)
                ->action(fn () => $this->run(fn (AffiliateRegistrationService $service, User $admin): AffiliateProfile => $service->approve($this->record(), $admin), 'Affiliate approved')),
            Action::make('reject')
                ->label('Reject')
                ->color('danger')
                ->requiresConfirmation()
                ->form([Textarea::make('reason')->maxLength(500)])
                ->visible(fn (): bool => $this->record()->status === AffiliateProfileStatus::Pending)
                ->action(fn (array $data) => $this->run(fn (AffiliateRegistrationService $service, User $admin): AffiliateProfile => $service->reject($this->record(), $admin, $data['reason'] ?? null), 'Affiliate rejected')),
            Action::make('suspend')
                ->label('Suspend')
                ->color('danger')
                ->requiresConfirmation()
                ->form([Textarea::make('reason')->maxLength(500)])
                ->visible(fn (): bool => $this->record()->status === AffiliateProfileStatus::Active)
                ->action(fn (array $data) => $this->run(fn (AffiliateRegistrationService $service, User $admin): AffiliateProfile => $service->suspend($this->record(), $admin, $data['reason'] ?? null), 'Affiliate suspended')),
            Action::make('reactivate')
                ->label('Reactivate')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record()->status === AffiliateProfileStatus::Suspended)
                ->action(fn () => $this->run(fn (AffiliateRegistrationService $service, User $admin): AffiliateProfile => $service->reactivate($this->record(), $admin), 'Affiliate reactivated')),
            Action::make('close')
                ->label('Close')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record()->status !== AffiliateProfileStatus::Closed)
                ->action(fn () => $this->run(fn (AffiliateRegistrationService $service, User $admin): AffiliateProfile => $service->close($this->record(), $admin), 'Affiliate closed')),
        ];
    }

    private function record(): AffiliateProfile
    {
        /** @var AffiliateProfile $record */
        $record = $this->getRecord();

        return $record;
    }

    private function run(callable $callback, string $successMessage): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User || ! $actor->isPlatformAdmin()) {
            abort(403);
        }

        try {
            $callback(app(AffiliateRegistrationService::class), $actor);
            $this->record()->refresh();
            Notification::make()->title($successMessage)->success()->send();
        } catch (AffiliateException $exception) {
            Notification::make()->title('Action failed')->body($exception->getMessage())->danger()->send();
        }
    }
}
