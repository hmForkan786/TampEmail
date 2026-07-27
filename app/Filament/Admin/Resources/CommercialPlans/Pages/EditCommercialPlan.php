<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CommercialPlans\Pages;

use App\Exceptions\CommercialManagementException;
use App\Filament\Admin\Resources\CommercialPlans\CommercialPlanResource;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commercial\CommercialPlanManagementService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

final class EditCommercialPlan extends EditRecord
{
    protected static string $resource = CommercialPlanResource::class;

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
}
