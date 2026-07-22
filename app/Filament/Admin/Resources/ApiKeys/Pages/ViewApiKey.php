<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ApiKeys\Pages;

use App\Actions\ApiKey\RevokeApiKeyAction;
use App\DTOs\ApiKey\RevokeApiKeyData;
use App\Exceptions\ApiKeyRevocationNotAllowedException;
use App\Exceptions\ApiKeyRevocationTargetUnavailableException;
use App\Filament\Admin\Resources\ApiKeys\ApiKeyResource;
use App\Models\ApiKey;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;
use Throwable;

class ViewApiKey extends ViewRecord
{
    protected static string $resource = ApiKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('revoke')
                ->label('Revoke API Key')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Revoke API key')
                ->modalDescription(fn (): string => $this->revokeConfirmationMessage())
                ->modalSubmitActionLabel('Revoke key')
                ->visible(fn (): bool => $this->canRevokeApiKey())
                ->action(function (RevokeApiKeyAction $revokeApiKey): void {
                    $actor = auth()->user();

                    if (! $actor instanceof User) {
                        Notification::make()
                            ->title('API key revocation not allowed')
                            ->body('Only an active platform admin may revoke a non-revoked API key.')
                            ->danger()
                            ->send();

                        return;
                    }

                    /** @var ApiKey $apiKey */
                    $apiKey = $this->getRecord();

                    try {
                        $result = $revokeApiKey->execute(new RevokeApiKeyData(
                            actorUserId: (string) $actor->getKey(),
                            apiKeyId: (string) $apiKey->getKey(),
                            source: 'filament',
                        ));
                    } catch (ApiKeyRevocationNotAllowedException|ApiKeyRevocationTargetUnavailableException $exception) {
                        Notification::make()
                            ->title('API key revocation not allowed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('API key revocation failed')
                            ->body('An unexpected error prevented the API key from being revoked.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->getRecord()->refresh();

                    if (! $result->changed) {
                        Notification::make()
                            ->title('API key already revoked')
                            ->body('This API key has already been revoked.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('API key revoked')
                        ->body("API key \"{$apiKey->name}\" has been revoked.")
                        ->success()
                        ->send();
                }),
        ];
    }

    private function canRevokeApiKey(): bool
    {
        $actor = auth()->user();
        $record = $this->getRecord();

        if (! $actor instanceof User || ! $record instanceof ApiKey) {
            return false;
        }

        if (! $record->isActive()) {
            return false;
        }

        return Gate::forUser($actor)->allows('view', $record)
            && $actor->isPlatformAdmin();
    }

    private function revokeConfirmationMessage(): string
    {
        $record = $this->getRecord();

        if (! $record instanceof ApiKey) {
            return 'Are you sure you want to revoke this API key? This cannot be undone from this screen.';
        }

        $prefix = $record->key_prefix !== ''
            ? " (prefix: {$record->key_prefix})"
            : '';

        return "Are you sure you want to revoke \"{$record->name}\"{$prefix}? The key will stop working immediately. This cannot be undone from this screen.";
    }
}
