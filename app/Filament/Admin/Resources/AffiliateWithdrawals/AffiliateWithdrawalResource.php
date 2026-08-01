<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AffiliateWithdrawals;

use App\Enums\AffiliatePayoutMethod;
use App\Enums\AffiliateWithdrawalStatus;
use App\Filament\Admin\Resources\AffiliateWithdrawals\Pages\ListAffiliateWithdrawals;
use App\Filament\Admin\Resources\AffiliateWithdrawals\Pages\ViewAffiliateWithdrawal;
use App\Models\AffiliateWithdrawal;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class AffiliateWithdrawalResource extends Resource
{
    protected static ?string $model = AffiliateWithdrawal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Affiliates';

    protected static ?int $navigationSort = 60;

    public static function getNavigationLabel(): string
    {
        return 'Withdrawals';
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
            TextEntry::make('amount_minor')->label('Amount (minor)')->numeric(),
            TextEntry::make('currency'),
            TextEntry::make('status')->badge(),
            TextEntry::make('payout_method')->formatStateUsing(fn (AffiliatePayoutMethod $state): string => $state->label()),
            TextEntry::make('requested_at')->dateTime(),
            TextEntry::make('reviewer.email')->label('Reviewed by')->placeholder('—'),
            TextEntry::make('reviewed_at')->dateTime()->placeholder('—'),
            TextEntry::make('approver.email')->label('Approved by')->placeholder('—'),
            TextEntry::make('approved_at')->dateTime()->placeholder('—'),
            TextEntry::make('payer.email')->label('Paid by')->placeholder('—'),
            TextEntry::make('paid_at')->dateTime()->placeholder('—'),
            TextEntry::make('external_reference')->placeholder('—'),
            TextEntry::make('rejection_reason')->placeholder('—')->columnSpanFull(),
            Section::make('Payout details')
                ->description('Sensitive payout destination — visible to platform admins only.')
                ->visible(fn (): bool => auth()->user() instanceof User && auth()->user()->isPlatformAdmin())
                ->schema([
                    TextEntry::make('payout_details_snapshot_encrypted')
                        ->label('Payout details')
                        ->state(fn (AffiliateWithdrawal $record): string => (string) $record->payout_details_snapshot_encrypted)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('requested_at', 'desc')
            ->columns([
                TextColumn::make('profile.affiliate_code')->label('Affiliate code')->badge()->searchable(),
                TextColumn::make('amount_minor')->label('Amount (minor)')->numeric()->sortable(),
                TextColumn::make('currency'),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('payout_method')->label('Payout method')->formatStateUsing(fn (AffiliatePayoutMethod $state): string => $state->label()),
                TextColumn::make('requested_at')->dateTime()->sortable(),
                TextColumn::make('external_reference')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(AffiliateWithdrawalStatus::labels()),
                SelectFilter::make('payout_method')->options(AffiliatePayoutMethod::labels()),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['profile.user', 'reviewer', 'approver', 'payer']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAffiliateWithdrawals::route('/'),
            'view' => ViewAffiliateWithdrawal::route('/{record}'),
        ];
    }
}
