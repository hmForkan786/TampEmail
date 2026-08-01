<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AdCampaigns\Pages;

use App\Enums\AdCampaignStatus;
use App\Enums\AdProviderName;
use App\Events\Ads\CampaignDisabled;
use App\Events\Ads\CampaignEnabled;
use App\Filament\Admin\Resources\AdCampaigns\AdCampaignResource;
use App\Models\AdCampaign;
use App\Services\Ads\AdProviderRegistry;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class CreateAdCampaign extends CreateRecord
{
    protected static string $resource = AdCampaignResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return self::normalizeFormData($data);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $placementIds = $data['placements'] ?? [];
        unset($data['placements'], $data['provider_config_json']);

        $this->assertProviderConfig($data);

        /** @var AdCampaign $campaign */
        $campaign = AdCampaign::query()->create($data);
        $campaign->placements()->sync($placementIds);

        if ($campaign->status === AdCampaignStatus::Active) {
            event(new CampaignEnabled($campaign));
        }

        return $campaign;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeFormData(array $data): array
    {
        $json = $data['provider_config_json'] ?? null;
        if (is_string($json) && trim($json) !== '') {
            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
                throw ValidationException::withMessages(['provider_config_json' => 'Provider config must be valid JSON.']);
            }
            $data['provider_config'] = $decoded;
        } else {
            $data['provider_config'] = $data['provider_config'] ?? [];
        }

        if (! isset($data['targeting']) || ! is_array($data['targeting'])) {
            $data['targeting'] = ['audience' => 'all'];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function assertProviderConfig(array $data): void
    {
        $provider = AdProviderName::normalize((string) ($data['provider'] ?? ''));
        $config = is_array($data['provider_config'] ?? null) ? $data['provider_config'] : [];
        $adapter = app(AdProviderRegistry::class)->get($provider);
        if (! $adapter->validateConfig($config)) {
            throw ValidationException::withMessages(['provider_config_json' => 'Provider config failed validation for '.$provider.'.']);
        }
    }
}
