<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateCommissionPlans;

use App\Enums\AffiliateCommissionPlanStatus;
use App\Enums\AffiliateCommissionType;
use App\Filament\Admin\Resources\AffiliateCommissionPlans\Pages\CreateAffiliateCommissionPlan;
use App\Filament\Admin\Resources\AffiliateCommissionPlans\Pages\EditAffiliateCommissionPlan;
use App\Filament\Admin\Resources\AffiliateCommissionPlans\Pages\ListAffiliateCommissionPlans;
use App\Models\AffiliateCommissionPlan;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

final class AffiliateCommissionPlanResource extends Resource
{
    protected static ?string $model = AffiliateCommissionPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return 'Commission Plans';
    }

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
            Section::make('Plan')->schema([
                TextInput::make('name')->required()->maxLength(160),
                Select::make('status')->options(AffiliateCommissionPlanStatus::labels())->required()->default(AffiliateCommissionPlanStatus::Active->value),
                Select::make('commission_type')->options(AffiliateCommissionType::labels())->required(),
                TextInput::make('percentage_bps')->label('Percentage (bps)')->numeric()->minValue(0)->maxValue(10000)->nullable()->helperText('100 = 1%. Used for percentage-based plans.'),
                TextInput::make('fixed_amount_minor')->label('Fixed amount (minor units)')->numeric()->minValue(0)->nullable()->helperText('Used for fixed-amount plans.'),
                TextInput::make('currency')->maxLength(3)->nullable()->helperText('Required for fixed-amount plans'),
            ])->columns(2),
            Section::make('Limits & windows')->schema([
                TextInput::make('minimum_order_minor')->label('Minimum order (minor units)')->numeric()->minValue(0)->nullable(),
                TextInput::make('maximum_commission_minor')->label('Maximum commission (minor units)')->numeric()->minValue(0)->nullable(),
                TextInput::make('cookie_window_days')->numeric()->minValue(1)->default(30)->required(),
                TextInput::make('commission_hold_days')->numeric()->minValue(0)->default(14)->required(),
                Toggle::make('new_customer_only')->default(true),
                Toggle::make('recurring_commission_enabled')->default(false),
                TextInput::make('recurring_cycles')->numeric()->minValue(1)->nullable()->helperText('Only used when recurring commission is enabled.'),
            ])->columns(3),
            Section::make('Schedule')->schema([
                DateTimePicker::make('starts_at'),
                DateTimePicker::make('ends_at'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('commission_type')->badge()->formatStateUsing(fn (AffiliateCommissionType $state): string => $state->label()),
                TextColumn::make('percentage_bps')->label('Bps')->placeholder('—'),
                TextColumn::make('fixed_amount_minor')->label('Fixed (minor)')->placeholder('—'),
                TextColumn::make('currency')->placeholder('—'),
                TextColumn::make('cookie_window_days')->label('Cookie days'),
                TextColumn::make('commission_hold_days')->label('Hold days'),
                IconColumn::make('new_customer_only')->label('New customers only')->boolean(),
                TextColumn::make('ends_at')->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(AffiliateCommissionPlanStatus::labels()),
                SelectFilter::make('commission_type')->options(AffiliateCommissionType::labels()),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAffiliateCommissionPlans::route('/'),
            'create' => CreateAffiliateCommissionPlan::route('/create'),
            'edit' => EditAffiliateCommissionPlan::route('/{record}/edit'),
        ];
    }
}
