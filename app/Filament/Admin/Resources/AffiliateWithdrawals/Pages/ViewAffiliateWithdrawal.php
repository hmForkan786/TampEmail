<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateWithdrawals\Pages;

use App\Enums\AffiliateWithdrawalStatus;
use App\Exceptions\Affiliates\AffiliateException;
use App\Filament\Admin\Resources\AffiliateWithdrawals\AffiliateWithdrawalResource;
use App\Models\AffiliateWithdrawal;
use App\Models\User;
use App\Services\Affiliates\AffiliateWithdrawalService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewAffiliateWithdrawal extends ViewRecord
{
    protected static string $resource = AffiliateWithdrawalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startReview')
                ->label('Start review')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record()->status === AffiliateWithdrawalStatus::Requested)
                ->action(fn () => $this->run(fn (AffiliateWithdrawalService $service, User $admin): AffiliateWithdrawal => $service->startReview($this->record(), $admin), 'Withdrawal moved to review')),
            Action::make('approve')
                ->label('Approve')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => in_array($this->record()->status, [AffiliateWithdrawalStatus::Requested, AffiliateWithdrawalStatus::UnderReview], true))
                ->action(fn () => $this->run(fn (AffiliateWithdrawalService $service, User $admin): AffiliateWithdrawal => $service->approve($this->record(), $admin), 'Withdrawal approved')),
            Action::make('reject')
                ->label('Reject')
                ->color('danger')
                ->requiresConfirmation()
                ->form([TextInput::make('reason')->label('Rejection reason')->maxLength(500)])
                ->visible(fn (): bool => in_array($this->record()->status, [AffiliateWithdrawalStatus::Requested, AffiliateWithdrawalStatus::UnderReview, AffiliateWithdrawalStatus::Approved], true))
                ->action(fn (array $data) => $this->run(fn (AffiliateWithdrawalService $service, User $admin): AffiliateWithdrawal => $service->reject($this->record(), $admin, $data['reason'] ?? null), 'Withdrawal rejected')),
            Action::make('markProcessing')
                ->label('Mark processing')
                ->visible(fn (): bool => $this->record()->status === AffiliateWithdrawalStatus::Approved)
                ->action(fn () => $this->run(fn (AffiliateWithdrawalService $service, User $admin): AffiliateWithdrawal => $service->markProcessing($this->record(), $admin), 'Withdrawal marked processing')),
            Action::make('markPaid')
                ->label('Mark paid')
                ->color('success')
                ->requiresConfirmation()
                ->form([TextInput::make('external_reference')->label('External reference')->required()->maxLength(255)])
                ->visible(fn (): bool => in_array($this->record()->status, [AffiliateWithdrawalStatus::Approved, AffiliateWithdrawalStatus::Processing], true))
                ->action(fn (array $data) => $this->run(fn (AffiliateWithdrawalService $service, User $admin): AffiliateWithdrawal => $service->markPaid($this->record(), $admin, (string) $data['external_reference']), 'Withdrawal marked paid')),
            Action::make('cancel')
                ->label('Cancel')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => in_array($this->record()->status, [AffiliateWithdrawalStatus::Requested, AffiliateWithdrawalStatus::UnderReview, AffiliateWithdrawalStatus::Approved], true))
                ->action(fn () => $this->run(fn (AffiliateWithdrawalService $service, User $admin): AffiliateWithdrawal => $service->cancel($this->record(), $admin), 'Withdrawal cancelled')),
        ];
    }

    private function record(): AffiliateWithdrawal
    {
        /** @var AffiliateWithdrawal $record */
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
            $callback(app(AffiliateWithdrawalService::class), $actor);
            $this->record()->refresh();
            Notification::make()->title($successMessage)->success()->send();
        } catch (AffiliateException $exception) {
            Notification::make()->title('Action failed')->body($exception->getMessage())->danger()->send();
        }
    }
}
