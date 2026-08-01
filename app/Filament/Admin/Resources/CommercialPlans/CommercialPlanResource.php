<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CommercialPlans;

use App\Filament\Admin\Resources\CommercialPlans\Pages\EditCommercialPlan;
use App\Filament\Admin\Resources\CommercialPlans\Pages\ListCommercialPlans;
use App\Models\Plan;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class CommercialPlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Commercial';

    protected static ?int $navigationSort = 10;

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public static function canEdit(mixed $record): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Plan identity')->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('slug')->disabled()->dehydrated(false)->helperText('Canonical and stable plan identity cannot be changed here.'),
                Textarea::make('description')->maxLength(2000)->columnSpanFull(),
            ])->columns(2),
            Section::make('Commercial settings')->schema([
                TextInput::make('price_monthly')->numeric()->minValue(0)->required(),
                TextInput::make('price_yearly')->numeric()->minValue(0)->required(),
                TextInput::make('currency')->length(3)->required(),
                Toggle::make('is_active')->helperText('Deactivating Premium causes its subscribers to fall back to Free.'),
                TextInput::make('display_order')->numeric()->minValue(0)->required(),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(), TextColumn::make('slug')->badge()->color(fn (string $state): string => in_array($state, ['free', 'premium'], true) ? 'warning' : 'gray')->searchable(),
            IconColumn::make('is_active')->label('Active')->boolean(), TextColumn::make('price_monthly')->money(fn (Plan $record): string => $record->currency), TextColumn::make('features_count')->label('Features')->numeric(), TextColumn::make('subscriptions_count')->label('Subscriptions')->numeric(), TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->filters([TernaryFilter::make('is_active')->label('Active')])->recordActions([EditAction::make()])->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['features', 'subscriptions']);
    }

    public static function getPages(): array
    {
        return ['index' => ListCommercialPlans::route('/'), 'edit' => EditCommercialPlan::route('/{record}/edit')];
    }
}
