<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AdCampaigns;

use App\Enums\AdAudience;
use App\Enums\AdCampaignPurpose;
use App\Enums\AdCampaignStatus;
use App\Enums\AdPromotionKind;
use App\Enums\AdProviderName;
use App\Filament\Admin\Resources\AdCampaigns\Pages\CreateAdCampaign;
use App\Filament\Admin\Resources\AdCampaigns\Pages\EditAdCampaign;
use App\Filament\Admin\Resources\AdCampaigns\Pages\ListAdCampaigns;
use App\Models\AdCampaign;
use App\Models\AdPlacement;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

final class AdCampaignResource extends Resource
{
    protected static ?string $model = AdCampaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Ads';

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        $implemented = collect(AdProviderName::cases())
            ->filter(fn (AdProviderName $p): bool => $p->isImplemented())
            ->mapWithKeys(fn (AdProviderName $p): array => [$p->value => $p->label()])
            ->all();

        return $schema->components([
            Section::make('Campaign')->schema([
                TextInput::make('name')->required()->maxLength(200),
                Select::make('provider')->options($implemented)->required(),
                Select::make('purpose')->options(AdCampaignPurpose::labels())->required()->default(AdCampaignPurpose::Monetization->value),
                Select::make('promotion_kind')->options(AdPromotionKind::labels())->nullable(),
                Select::make('status')->options(AdCampaignStatus::labels())->required()->default(AdCampaignStatus::Draft->value),
                TextInput::make('priority')->numeric()->minValue(1)->default(100)->required(),
            ])->columns(2),
            Section::make('Schedule & caps')->schema([
                DateTimePicker::make('starts_at'),
                DateTimePicker::make('ends_at'),
                TextInput::make('daily_budget')->numeric()->minValue(1)->nullable()->helperText('Max impressions per day'),
                TextInput::make('max_impressions')->numeric()->minValue(1)->nullable(),
                TextInput::make('max_clicks')->numeric()->minValue(1)->nullable(),
            ])->columns(3),
            Section::make('Targeting & provider config')->schema([
                Select::make('targeting.audience')->label('Audience')->options(AdAudience::labels())->default(AdAudience::All->value),
                Select::make('placements')->label('Placements')->multiple()->options(fn (): array => AdPlacement::query()->orderBy('display_order')->pluck('name', 'id')->all())->required(),
                Textarea::make('provider_config_json')->label('Provider config (JSON)')->rows(6)->helperText('AdSense: publisher_id, slot_id, responsive. Direct: image_url, click_url. House: headline, body, cta_label, cta_url. Custom: html.')->columnSpanFull(),
                Textarea::make('notes')->rows(3)->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('provider')->badge()->formatStateUsing(fn ($state): string => $state instanceof AdProviderName ? $state->label() : (string) $state),
            TextColumn::make('purpose')->badge(),
            TextColumn::make('status')->badge(),
            TextColumn::make('priority')->sortable(),
            TextColumn::make('impressions_total')->label('Impressions')->numeric(),
            TextColumn::make('clicks_total')->label('Clicks')->numeric(),
            TextColumn::make('ends_at')->dateTime()->placeholder('—'),
        ])->filters([
            SelectFilter::make('status')->options(AdCampaignStatus::labels()),
            SelectFilter::make('provider')->options(AdProviderName::labels()),
            SelectFilter::make('purpose')->options(AdCampaignPurpose::labels()),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdCampaigns::route('/'),
            'create' => CreateAdCampaign::route('/create'),
            'edit' => EditAdCampaign::route('/{record}/edit'),
        ];
    }
}
