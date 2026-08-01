<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateAttributions;

use App\Enums\AffiliateAttributionStatus;
use App\Filament\Admin\Resources\AffiliateAttributions\Pages\ListAffiliateAttributions;
use App\Filament\Admin\Resources\AffiliateAttributions\Pages\ViewAffiliateAttribution;
use App\Models\AffiliateAttribution;
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

final class AffiliateAttributionResource extends Resource
{
    protected static ?string $model = AffiliateAttribution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCursorArrowRays;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static ?int $navigationSort = 30;

    public static function getNavigationLabel(): string
    {
        return 'Attributions';
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
            TextEntry::make('profile.user.email')->label('Affiliate'),
            TextEntry::make('referral_code'),
            TextEntry::make('status')->badge(),
            TextEntry::make('landing_url')->placeholder('—')->columnSpanFull(),
            TextEntry::make('referrer_url')->placeholder('—')->columnSpanFull(),
            TextEntry::make('utm_source')->placeholder('—'),
            TextEntry::make('utm_medium')->placeholder('—'),
            TextEntry::make('utm_campaign')->placeholder('—'),
            TextEntry::make('convertedUser.email')->label('Converted user')->placeholder('—'),
            TextEntry::make('first_seen_at')->dateTime(),
            TextEntry::make('last_seen_at')->dateTime(),
            TextEntry::make('expires_at')->dateTime(),
            TextEntry::make('converted_at')->dateTime()->placeholder('—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('profile.affiliate_code')->label('Affiliate code')->badge()->searchable(),
                TextColumn::make('referral_code')->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('utm_source')->placeholder('—'),
                TextColumn::make('convertedUser.email')->label('Converted user')->placeholder('—'),
                TextColumn::make('first_seen_at')->dateTime()->sortable(),
                TextColumn::make('expires_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(AffiliateAttributionStatus::labels()),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['profile.user', 'convertedUser']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAffiliateAttributions::route('/'),
            'view' => ViewAffiliateAttribution::route('/{record}'),
        ];
    }
}
