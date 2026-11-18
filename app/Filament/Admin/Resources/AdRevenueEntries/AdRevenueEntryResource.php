<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AdRevenueEntries;

use App\Enums\AdProviderName;
use App\Filament\Admin\Resources\AdRevenueEntries\Pages\CreateAdRevenueEntry;
use App\Filament\Admin\Resources\AdRevenueEntries\Pages\ListAdRevenueEntries;
use App\Models\AdCampaign;
use App\Models\AdRevenueEntry;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

final class AdRevenueEntryResource extends Resource
{
    protected static ?string $model = AdRevenueEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Ads';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Revenue';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('ad_campaign_id')->label('Campaign')->options(fn (): array => AdCampaign::query()->orderBy('name')->pluck('name', 'id')->all())->searchable()->nullable(),
            Select::make('provider')->options(AdProviderName::labels())->nullable(),
            DatePicker::make('earned_on')->required(),
            TextInput::make('amount_minor')->label('Amount (minor units)')->numeric()->minValue(0)->required()->helperText('e.g. 1250 = $12.50'),
            TextInput::make('currency')->length(3)->default('USD')->required(),
            TextInput::make('source')->maxLength(80)->nullable(),
            Textarea::make('notes')->rows(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('earned_on')->date()->sortable(),
            TextColumn::make('campaign.name')->placeholder('—'),
            TextColumn::make('provider')->badge()->placeholder('—'),
            TextColumn::make('amount_minor')->label('Amount')->formatStateUsing(fn (int $state, AdRevenueEntry $record): string => number_format($state / 100, 2).' '.$record->currency),
            TextColumn::make('source')->placeholder('—'),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ])->defaultSort('earned_on', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdRevenueEntries::route('/'),
            'create' => CreateAdRevenueEntry::route('/create'),
        ];
    }
}
