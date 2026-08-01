<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CommercialPlans\Pages;

use App\Exceptions\CommercialManagementException;
use App\Filament\Admin\Resources\CommercialPlans\CommercialPlanResource;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commercial\CommercialPlanManagementService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

final class EditCommercialPlan extends EditRecord
{
    protected static string $resource = CommercialPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('updateFeature')
                ->label('Update feature entitlement')
                ->modalDescription('Boolean values are enabled or disabled. Numeric 0 means disabled / denied.')
                ->form([
                    Select::make('feature_id')->label('Feature')->options(fn (): array => $this->plan()->features()->orderBy('category')->orderBy('display_order')->pluck('name', 'features.id')->all())->required()->live(),
                    Toggle::make('enabled')->label('Enabled / Disabled')->default(false),
                    TextInput::make('limit')->label('Numeric limit')->numeric()->minValue(0)->helperText('0 = Disabled / Denied.'),
                    TextInput::make('reason')->required()->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $plan = $this->plan();
                    if (! $actor instanceof User) {
                        abort(403);
                    }
                    $feature = Feature::query()->whereKey($data['feature_id'])->firstOrFail();
                    $value = $feature->value_type->value === 'boolean'
                        ? ['enabled' => (bool) ($data['enabled'] ?? false)]
                        : ['limit' => isset($data['limit']) ? (int) $data['limit'] : null];
                    try {
                        app(CommercialPlanManagementService::class)->updateFeatureValue($actor, $plan, $feature, $value, $plan->updated_at?->toIso8601String() ?? '', (string) $data['reason']);
                        $plan->refresh();
                        Notification::make()->title('Feature entitlement updated')->success()->send();
                    } catch (CommercialManagementException $exception) {
                        Notification::make()->title('Feature update blocked')->body($exception->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    protected function handleRecordUpdate(mixed $record, array $data): Plan
    {
        $actor = auth()->user();
        if (! $actor instanceof User || ! $record instanceof Plan) {
            throw new CommercialManagementException('Commercial plan update is not authorized.');
        }
        try {
            return app(CommercialPlanManagementService::class)->updatePlan($actor, $record, $data, $record->updated_at?->toIso8601String() ?? '', 'Filament plan edit');
        } catch (CommercialManagementException $exception) {
            Notification::make()->title('Plan update blocked')->body($exception->getMessage())->danger()->send();
            throw $exception;
        }
    }

    private function plan(): Plan
    {
        $record = $this->getRecord();
        if (! $record instanceof Plan) {
            throw new CommercialManagementException('Commercial plan record is unavailable.');
        }

        return $record;
    }
}
