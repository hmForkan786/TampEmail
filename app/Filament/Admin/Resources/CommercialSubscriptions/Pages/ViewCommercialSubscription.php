<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CommercialSubscriptions\Pages;

use App\Filament\Admin\Resources\CommercialSubscriptions\CommercialSubscriptionResource;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscription\SubscriptionLifecycleService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

final class ViewCommercialSubscription extends ViewRecord
{
    protected static string $resource = CommercialSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('activate')->label('Activate')->form([DateTimePicker::make('ends_at')->required()->seconds(false)])->action(fn (array $data) => $this->lifecycle('activate', now(), Carbon::parse($data['ends_at']))),
            Action::make('cancelImmediately')->label('Cancel now')->color('danger')->requiresConfirmation()->action(fn () => $this->lifecycle('cancelImmediately')),
            Action::make('cancelAtPeriodEnd')->label('Cancel at period end')->requiresConfirmation()->action(fn () => $this->lifecycle('cancelAtPeriodEnd')),
            Action::make('renew')->label('Renew / reactivate')->form([DateTimePicker::make('ends_at')->required()->seconds(false)])->action(fn (array $data) => $this->lifecycle('renew', Carbon::parse($data['ends_at']))),
            Action::make('expire')->label('Expire now')->color('danger')->requiresConfirmation()->action(fn () => $this->lifecycle('expireNow')),
        ];
    }

    private function lifecycle(string $method, mixed ...$arguments): void
    {
        $actor = auth()->user();
        $record = $this->getRecord();
        if (! $actor instanceof User || ! $record instanceof Subscription || ! $actor->isPlatformAdmin()) {
            abort(403);
        } try {
            app(SubscriptionLifecycleService::class)->{$method}(...[$record, ...$arguments, (string) $actor->getKey(), 'filament']);
            $record->refresh();
            Notification::make()->title('Subscription lifecycle updated')->success()->send();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title('Lifecycle action blocked')->body($exception->getMessage())->danger()->send();
        }
    }
}
