<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AdCampaigns\Pages;

use App\Enums\AdCampaignStatus;
use App\Events\Ads\CampaignDisabled;
use App\Events\Ads\CampaignEnabled;
use App\Filament\Admin\Resources\AdCampaigns\AdCampaignResource;
use App\Models\AdCampaign;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditAdCampaign extends EditRecord
{
    protected static string $resource = AdCampaignResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['provider_config_json'] = json_encode($this->getRecord()->provider_config ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $data['placements'] = $this->getRecord()->placements()->pluck('ad_placements.id')->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CreateAdCampaign::normalizeFormData($data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var AdCampaign $record */
        $placementIds = $data['placements'] ?? [];
        unset($data['placements'], $data['provider_config_json']);
        CreateAdCampaign::assertProviderConfig($data);

        $previous = $record->status;
        $record->update($data);
        $record->placements()->sync($placementIds);

        if ($previous !== AdCampaignStatus::Active && $record->status === AdCampaignStatus::Active) {
            event(new CampaignEnabled($record));
        }
        if ($previous === AdCampaignStatus::Active && $record->status !== AdCampaignStatus::Active) {
            event(new CampaignDisabled($record));
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview decision')
                ->url(fn (): string => url('/api/v1/ad/'.($this->getRecord()->placements()->first()?->key ?? 'dashboard').'?track=0'))
                ->openUrlInNewTab(),
        ];
    }
}
