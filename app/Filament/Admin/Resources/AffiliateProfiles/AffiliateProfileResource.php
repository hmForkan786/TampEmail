<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateProfiles;

use App\Enums\AffiliatePayoutMethod;
use App\Enums\AffiliateProfileStatus;
use App\Filament\Admin\Resources\AffiliateProfiles\Pages\EditAffiliateProfile;
use App\Filament\Admin\Resources\AffiliateProfiles\Pages\ListAffiliateProfiles;
use App\Filament\Admin\Resources\AffiliateProfiles\Pages\ViewAffiliateProfile;
use App\Models\AffiliateProfile;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class AffiliateProfileResource extends Resource
{
    protected static ?string $model = AffiliateProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return 'Affiliate Profiles';
    }

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
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('promotion_channel')->maxLength(100),
            TextInput::make('website_url')->url()->maxLength(255),
            Textarea::make('audience_description')->rows(3)->maxLength(2000)->columnSpanFull(),
            TextInput::make('expected_traffic')->maxLength(100),
            Textarea::make('application_notes')->rows(3)->maxLength(2000)->columnSpanFull(),
            Select::make('commission_plan_id')
                ->label('Commission plan')
                ->relationship('plan', 'name')
                ->searchable()
                ->preload(),
        ])->columns(2);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('user.email')->label('User'),
            TextEntry::make('affiliate_code')->label('Affiliate code')->badge(),
            TextEntry::make('status')->badge(),
            TextEntry::make('plan.name')->label('Commission plan')->placeholder('—'),
            TextEntry::make('payout_method')->label('Payout method')->formatStateUsing(fn (?AffiliatePayoutMethod $state): string => $state?->label() ?? '—'),
            TextEntry::make('promotion_channel')->placeholder('—'),
            TextEntry::make('website_url')->placeholder('—'),
            TextEntry::make('audience_description')->placeholder('—')->columnSpanFull(),
            TextEntry::make('expected_traffic')->placeholder('—'),
            TextEntry::make('application_notes')->placeholder('—')->columnSpanFull(),
            TextEntry::make('approver.email')->label('Approved by')->placeholder('—'),
            TextEntry::make('approved_at')->dateTime()->placeholder('—'),
            TextEntry::make('suspended_at')->dateTime()->placeholder('—'),
            TextEntry::make('rejected_at')->dateTime()->placeholder('—'),
            TextEntry::make('closed_at')->dateTime()->placeholder('—'),
            TextEntry::make('created_at')->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.email')->label('User')->searchable(),
                TextColumn::make('affiliate_code')->label('Code')->badge()->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('plan.name')->label('Plan')->placeholder('—'),
                TextColumn::make('payout_method')->label('Payout method')->formatStateUsing(fn (?AffiliatePayoutMethod $state): string => $state?->label() ?? '—'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(AffiliateProfileStatus::labels()),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'plan', 'approver']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAffiliateProfiles::route('/'),
            'view' => ViewAffiliateProfile::route('/{record}'),
            'edit' => EditAffiliateProfile::route('/{record}/edit'),
        ];
    }
}
