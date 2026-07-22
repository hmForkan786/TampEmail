<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ApiKeys;

use App\Enums\ApiKeyScope;
use App\Filament\Admin\Resources\ApiKeys\Pages\ListApiKeys;
use App\Filament\Admin\Resources\ApiKeys\Pages\ViewApiKey;
use App\Models\ApiKey;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ApiKeyResource extends Resource
{
    protected static ?string $model = ApiKey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 30;

    /**
     * Sensitive payload keys that must never be rendered in the admin UI.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'token',
        'plain_text_token',
        'key',
        'key_hash',
        'secret',
        'authorization',
        'password',
        'remember_token',
    ];

    public static function getNavigationLabel(): string
    {
        return 'API Keys';
    }

    public static function getModelLabel(): string
    {
        return 'API Key';
    }

    public static function getPluralModelLabel(): string
    {
        return 'API Keys';
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
                TextEntry::make('name')
                    ->label('Key name'),
                TextEntry::make('user.name')
                    ->label('Owner name')
                    ->placeholder('—'),
                TextEntry::make('user.email')
                    ->label('Owner email')
                    ->placeholder('—'),
                TextEntry::make('permissions_display')
                    ->label('Scopes')
                    ->state(fn (ApiKey $record): string => self::formatScopes($record->permissions))
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('status_display')
                    ->label('Status')
                    ->state(fn (ApiKey $record): string => self::resolveStatus($record))
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextEntry::make('revoked_at')
                    ->label('Revoked at')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('expires_at')
                    ->label('Expires at')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('last_used_at')
                    ->label('Last used')
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('rate_limit_per_minute')
                    ->label('Rate limit / minute')
                    ->numeric(),
                TextEntry::make('metadata_display')
                    ->label('Metadata')
                    ->state(fn (ApiKey $record): ?string => self::formatJsonState($record->metadata))
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->label('Created')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->label('Updated')
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
                    ->label('Key name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Owner')
                    ->placeholder('—')
                    ->description(fn (ApiKey $record): ?string => $record->user?->email)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('user', function (Builder $userQuery) use ($search): void {
                            $userQuery
                                ->where('email', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        });
                    }),
                TextColumn::make('scopes')
                    ->label('Scopes')
                    ->state(fn (ApiKey $record): string => self::formatScopes($record->permissions))
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (ApiKey $record): string => self::resolveStatus($record))
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextColumn::make('last_used_at')
                    ->label('Last used')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('revoked')
                    ->label('Revoked')
                    ->placeholder('All keys')
                    ->trueLabel('Revoked')
                    ->falseLabel('Not revoked')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('revoked_at'),
                        false: fn (Builder $query): Builder => $query->active(),
                    ),
                SelectFilter::make('lifecycle')
                    ->label('Status')
                    ->options([
                        'active' => 'Active',
                        'expired' => 'Expired',
                        'revoked' => 'Revoked',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'active' => $query->available(),
                            'expired' => $query->active()->expired(),
                            'revoked' => $query->whereNotNull('revoked_at'),
                            default => $query,
                        };
                    }),
                SelectFilter::make('user_id')
                    ->label('Owner')
                    ->relationship('user', 'email')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('scope')
                    ->label('Scope')
                    ->options(ApiKeyScope::labels())
                    ->query(function (Builder $query, array $data): Builder {
                        $scope = $data['value'] ?? null;

                        if (! filled($scope)) {
                            return $query;
                        }

                        return $query->whereJsonContains('permissions', $scope);
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['user']);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiKeys::route('/'),
            'view' => ViewApiKey::route('/{record}'),
        ];
    }

    /**
     * Resolve display status using existing model helpers.
     *
     * Precedence: revoked → expired → active.
     */
    public static function resolveStatus(ApiKey $apiKey): string
    {
        if (! $apiKey->isActive()) {
            return 'revoked';
        }

        if ($apiKey->isExpired()) {
            return 'expired';
        }

        return 'active';
    }

    /**
     * @param  list<string>|null  $permissions
     */
    public static function formatScopes(?array $permissions): string
    {
        if ($permissions === null || $permissions === []) {
            return '—';
        }

        return implode(', ', $permissions);
    }

    /**
     * Redact sensitive keys from nested arrays before display.
     *
     * @param  array<mixed>|null  $payload
     * @return array<mixed>|null
     */
    public static function redactSensitive(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                continue;
            }

            $redacted[$key] = is_array($value)
                ? self::redactSensitive($value)
                : $value;
        }

        return $redacted;
    }

    /**
     * Format array/JSON state as escaped pretty JSON for safe TextEntry display.
     */
    public static function formatJsonState(mixed $state): ?string
    {
        if ($state === null) {
            return null;
        }

        if (is_string($state)) {
            $decoded = json_decode($state, true);
            $state = json_last_error() === JSON_ERROR_NONE ? $decoded : $state;
        }

        if (! is_array($state)) {
            return is_scalar($state) ? (string) $state : null;
        }

        return json_encode(
            self::redactSensitive($state),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private static function statusColor(string $state): string
    {
        return match ($state) {
            'active' => 'success',
            'revoked' => 'danger',
            'expired' => 'warning',
            default => 'gray',
        };
    }

    private static function isSensitiveKey(string $key): bool
    {
        return in_array(strtolower($key), self::SENSITIVE_KEYS, true);
    }
}
