<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AdPlacements;

use App\Filament\Admin\Resources\AdPlacements\Pages\CreateAdPlacement;
use App\Filament\Admin\Resources\AdPlacements\Pages\EditAdPlacement;
use App\Filament\Admin\Resources\AdPlacements\Pages\ListAdPlacements;
use App\Models\AdPlacement;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

final class AdPlacementResource extends Resource
{
    protected static ?string $model = AdPlacement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Ads';

    protected static ?int $navigationSort = 20;

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
        return $schema->components([
            TextInput::make('key')->required()->maxLength(80)->unique(ignoreRecord: true)->helperText('Stable placement key used by GET /api/v1/ad/{placement}'),
            TextInput::make('name')->required()->maxLength(160),
            Textarea::make('description')->maxLength(500),
            TextInput::make('display_order')->numeric()->default(0)->required(),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('key')->searchable()->sortable()->copyable(),
            TextColumn::make('name')->searchable()->sortable(),
            IconColumn::make('is_active')->boolean(),
            TextColumn::make('display_order')->sortable(),
            TextColumn::make('campaigns_count')->counts('campaigns')->label('Campaigns'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdPlacements::route('/'),
            'create' => CreateAdPlacement::route('/create'),
            'edit' => EditAdPlacement::route('/{record}/edit'),
        ];
    }
}
