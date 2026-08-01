<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateConversions;

use App\Enums\AffiliateConversionStatus;
use App\Filament\Admin\Resources\AffiliateConversions\Pages\ListAffiliateConversions;
use App\Filament\Admin\Resources\AffiliateConversions\Pages\ViewAffiliateConversion;
use App\Models\AffiliateConversion;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class AffiliateConversionResource extends Resource
{
    protected static ?string $model = AffiliateConversion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static ?int $navigationSort = 40;

    public static function getNavigationLabel(): string
    {
        return 'Conversions';
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

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('profile.affiliate_code')->label('Affiliate code')->badge(),
            TextEntry::make('referredUser.email')->label('Referred user')->placeholder('—'),
            TextEntry::make('status')->badge(),
            TextEntry::make('order_amount_minor')->label('Order amount (minor)')->numeric(),
            TextEntry::make('commission_amount_minor')->label('Commission (minor)')->numeric(),
            TextEntry::make('currency'),
            TextEntry::make('reason_code')->placeholder('—'),
            TextEntry::make('qualified_at')->dateTime(),
            TextEntry::make('approved_at')->dateTime()->placeholder('—'),
            TextEntry::make('rejected_at')->dateTime()->placeholder('—'),
            TextEntry::make('reversed_at')->dateTime()->placeholder('—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('qualified_at', 'desc')
            ->columns([
                TextColumn::make('profile.affiliate_code')->label('Affiliate code')->badge()->searchable(),
                TextColumn::make('referredUser.email')->label('Referred user')->placeholder('—')->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('order_amount_minor')->label('Order (minor)')->numeric()->sortable(),
                TextColumn::make('commission_amount_minor')->label('Commission (minor)')->numeric()->sortable(),
                TextColumn::make('currency'),
                TextColumn::make('qualified_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(AffiliateConversionStatus::labels()),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['profile', 'referredUser']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAffiliateConversions::route('/'),
            'view' => ViewAffiliateConversion::route('/{record}'),
        ];
    }

}
