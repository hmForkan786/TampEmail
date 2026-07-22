<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Users;

use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\Pages\ViewUser;
use App\Models\User;
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

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return 'Users';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('email')
                    ->label('Email'),
                TextEntry::make('platform_role')
                    ->label('Platform role')
                    ->badge()
                    ->formatStateUsing(fn (?PlatformRole $state): ?string => $state?->label())
                    ->color(fn (?PlatformRole $state): string => match ($state) {
                        PlatformRole::Admin => 'danger',
                        PlatformRole::Operator => 'warning',
                        PlatformRole::User => 'gray',
                        default => 'gray',
                    }),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?UserStatus $state): ?string => $state?->label())
                    ->color(fn (?UserStatus $state): string => match ($state) {
                        UserStatus::Active => 'success',
                        UserStatus::Pending => 'warning',
                        UserStatus::Suspended => 'danger',
                        UserStatus::Banned => 'danger',
                        default => 'gray',
                    }),
                TextEntry::make('api_keys_count')
                    ->label('API keys')
                    ->numeric(),
                TextEntry::make('inboxes_count')
                    ->label('Inboxes')
                    ->numeric(),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('platform_role')
                    ->label('Platform role')
                    ->badge()
                    ->formatStateUsing(fn (?PlatformRole $state): ?string => $state?->label())
                    ->color(fn (?PlatformRole $state): string => match ($state) {
                        PlatformRole::Admin => 'danger',
                        PlatformRole::Operator => 'warning',
                        PlatformRole::User => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?UserStatus $state): ?string => $state?->label())
                    ->color(fn (?UserStatus $state): string => match ($state) {
                        UserStatus::Active => 'success',
                        UserStatus::Pending => 'warning',
                        UserStatus::Suspended => 'danger',
                        UserStatus::Banned => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('api_keys_count')
                    ->label('API keys')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('inboxes_count')
                    ->label('Inboxes')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('platform_role')
                    ->label('Platform role')
                    ->options(PlatformRole::labels()),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(UserStatus::labels()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['apiKeys', 'inboxes']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}
