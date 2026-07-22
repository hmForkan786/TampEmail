<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Actions\User\ChangeUserStatusAction;
use App\Actions\User\ChangePlatformRoleAction;
use App\DTOs\User\ChangePlatformRoleData;
use App\DTOs\User\ChangeUserStatusData;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Exceptions\PlatformRoleChangeNotAllowedException;
use App\Exceptions\PlatformRoleTargetUnavailableException;
use App\Exceptions\UserStatusChangeNotAllowedException;
use App\Exceptions\UserStatusTargetUnavailableException;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('changePlatformRole')
                ->label('Change Platform Role')
                ->visible(fn (): bool => $this->actorIsPlatformAdmin())
                ->modalHeading('Change platform role')
                ->modalSubmitActionLabel('Update role')
                ->form([
                    Select::make('platform_role')
                        ->label('Platform role')
                        ->options(PlatformRole::labels())
                        ->required()
                        ->default(fn (): ?string => $this->currentPlatformRoleValue()),
                ])
                ->action(function (array $data, ChangePlatformRoleAction $changePlatformRole): void {
                    if (! $this->actorIsPlatformAdmin()) {
                        Notification::make()
                            ->title('Platform role change not allowed')
                            ->body('Only an active platform admin may change platform roles.')
                            ->danger()
                            ->send();

                        return;
                    }

                    /** @var User $actor */
                    $actor = auth()->user();
                    /** @var User $target */
                    $target = $this->getRecord();
                    $newRole = $data['platform_role'] instanceof PlatformRole
                        ? $data['platform_role']
                        : PlatformRole::from((string) $data['platform_role']);

                    if ($target->platform_role === $newRole) {
                        Notification::make()
                            ->title('No platform role change')
                            ->body('The user already has the selected platform role.')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        $result = $changePlatformRole->execute(new ChangePlatformRoleData(
                            actorUserId: (string) $actor->getKey(),
                            targetUserId: (string) $target->getKey(),
                            newRole: $newRole,
                        ));
                    } catch (PlatformRoleChangeNotAllowedException|PlatformRoleTargetUnavailableException $exception) {
                        Notification::make()
                            ->title('Platform role change failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Platform role change failed')
                            ->body('An unexpected error prevented the platform role change.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->getRecord()->refresh();
                    $this->fillForm();

                    if (! $result->changed) {
                        Notification::make()
                            ->title('No platform role change')
                            ->body('The user already has the selected platform role.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Platform role updated')
                        ->body("Platform role changed to {$result->newRole->label()}.")
                        ->success()
                        ->send();
                }),
            Action::make('changeStatus')
                ->label('Change Status')
                ->visible(fn (): bool => $this->actorIsPlatformAdmin())
                ->modalHeading('Change user status')
                ->modalSubmitActionLabel('Update status')
                ->form([
                    Select::make('status')
                        ->label('Status')
                        ->options(UserStatus::labels())
                        ->required()
                        ->default(fn (): ?string => $this->currentStatusValue())
                        ->disableOptionWhen(
                            fn (string $value): bool => $value === $this->currentStatusValue()
                        )
                        ->helperText('The current status cannot be selected again.'),
                ])
                ->action(function (array $data, ChangeUserStatusAction $changeUserStatus): void {
                    if (! $this->actorIsPlatformAdmin()) {
                        Notification::make()
                            ->title('Status change not allowed')
                            ->body('Only an active platform admin may change user status.')
                            ->danger()
                            ->send();

                        return;
                    }

                    /** @var User $actor */
                    $actor = auth()->user();
                    /** @var User $target */
                    $target = $this->getRecord();

                    $newStatus = $data['status'] instanceof UserStatus
                        ? $data['status']
                        : UserStatus::from((string) $data['status']);

                    if ($target->status === $newStatus) {
                        Notification::make()
                            ->title('No status change')
                            ->body('The user already has the selected status.')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        $result = $changeUserStatus->execute(new ChangeUserStatusData(
                            actorUserId: (string) $actor->getKey(),
                            targetUserId: (string) $target->getKey(),
                            newStatus: $newStatus,
                        ));
                    } catch (UserStatusChangeNotAllowedException|UserStatusTargetUnavailableException $exception) {
                        Notification::make()
                            ->title('Status change failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Status change failed')
                            ->body('An unexpected error prevented the status change.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->getRecord()->refresh();
                    $this->fillForm();

                    if (! $result->changed) {
                        Notification::make()
                            ->title('No status change')
                            ->body('The user already has the selected status.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Status updated')
                        ->body("Status changed to {$result->newStatus->label()}.")
                        ->success()
                        ->send();
                }),
        ];
    }

    private function actorIsPlatformAdmin(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->isPlatformAdmin();
    }

    private function currentStatusValue(): ?string
    {
        $record = $this->getRecord();

        if (! $record instanceof User) {
            return null;
        }

        $status = $record->status;

        return $status instanceof UserStatus ? $status->value : null;
    }

    private function currentPlatformRoleValue(): ?string
    {
        $record = $this->getRecord();

        if (! $record instanceof User) {
            return null;
        }

        $role = $record->platform_role;

        return $role instanceof PlatformRole ? $role->value : null;
    }
}
