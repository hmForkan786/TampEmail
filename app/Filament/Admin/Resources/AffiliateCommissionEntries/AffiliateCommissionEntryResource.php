<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateCommissionEntries;

use App\Enums\AffiliateCommissionEntryStatus;
use App\Enums\AffiliateCommissionEntryType;
use App\Filament\Admin\Resources\AffiliateCommissionEntries\Pages\ListAffiliateCommissionEntries;
use App\Filament\Admin\Resources\AffiliateCommissionEntries\Pages\ViewAffiliateCommissionEntry;
use App\Models\AffiliateCommissionEntry;
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

final class AffiliateCommissionEntryResource extends Resource
{
    protected static ?string $model = AffiliateCommissionEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static ?int $navigationSort = 50;

    public static function getNavigationLabel(): string
    {
        return 'Commission Ledger';
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
            TextEntry::make('entry_type')->badge()->formatStateUsing(fn (AffiliateCommissionEntryType $state): string => $state->label()),
            TextEntry::make('amount_minor')->label('Amount (minor)')->numeric(),
            TextEntry::make('currency'),
            TextEntry::make('status')->badge(),
            TextEntry::make('available_at')->dateTime()->placeholder('—'),
            TextEntry::make('conversion_id')->label('Conversion')->placeholder('—'),
            TextEntry::make('withdrawal_id')->label('Withdrawal')->placeholder('—'),
            TextEntry::make('reason_code')->placeholder('—'),
            TextEntry::make('idempotency_key')->placeholder('—'),
            TextEntry::make('creator.email')->label('Created by')->placeholder('System'),
            TextEntry::make('created_at')->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('profile.affiliate_code')->label('Affiliate code')->badge()->searchable(),
                TextColumn::make('entry_type')->badge()->formatStateUsing(fn (AffiliateCommissionEntryType $state): string => $state->label()),
                TextColumn::make('amount_minor')->label('Amount (minor)')->numeric()->sortable(),
                TextColumn::make('currency'),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('available_at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('entry_type')->options(AffiliateCommissionEntryType::labels()),
                SelectFilter::make('status')->options(AffiliateCommissionEntryStatus::labels()),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['profile', 'creator']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAffiliateCommissionEntries::route('/'),
            'view' => ViewAffiliateCommissionEntry::route('/{record}'),
        ];
    }
}
