<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CommercialSubscriptions;

use App\Enums\SubscriptionStatus;
use App\Filament\Admin\Resources\CommercialSubscriptions\Pages\ListCommercialSubscriptions;
use App\Filament\Admin\Resources\CommercialSubscriptions\Pages\ViewCommercialSubscription;
use App\Models\Subscription;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class CommercialSubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Commercial';

    protected static ?int $navigationSort = 20;

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isPlatformAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('user.email')->label('User'), TextEntry::make('plan.slug')->label('Plan')->badge(), TextEntry::make('status')->badge(), TextEntry::make('starts_at')->dateTime(), TextEntry::make('ends_at')->dateTime()->placeholder('No end date'), TextEntry::make('trial_ends_at')->dateTime()->placeholder('—'), TextEntry::make('auto_renew')->badge(), TextEntry::make('cancel_at_period_end')->badge(), TextEntry::make('updated_at')->dateTime()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('user.email')->label('User')->searchable(), TextColumn::make('plan.slug')->label('Plan')->badge(), TextColumn::make('status')->badge()->sortable(), TextColumn::make('ends_at')->dateTime()->sortable(), IconColumn::make('auto_renew')->label('Renew')->boolean(), IconColumn::make('cancel_at_period_end')->label('Period-end cancel')->boolean(), TextColumn::make('updated_at')->dateTime()->sortable()])->filters([SelectFilter::make('status')->options(SubscriptionStatus::labels()), SelectFilter::make('plan')->relationship('plan', 'slug')])->recordActions([ViewAction::make()])->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'plan']);
    }

    public static function getPages(): array
    {
        return ['index' => ListCommercialSubscriptions::route('/'), 'view' => ViewCommercialSubscription::route('/{record}')];
    }
}
