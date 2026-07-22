<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuditLogs;

use App\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Admin\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'action';

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 20;

    /**
     * Sensitive payload keys that must never be rendered in the admin UI.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'remember_token',
        'token',
        'plain_text_token',
        'key_hash',
        'api_key',
        'secret',
        'authorization',
        'cookie',
    ];

    private const SAFE_KEYS = [
        'platform_role', 'status', 'revoked_at', 'name', 'hostname', 'port', 'provider', 'protocol',
        'pool_key', 'max_inboxes', 'is_active', 'priority', 'max_connections', 'timeout_seconds',
        'last_health_check_at', 'source', 'changed_fields', 'revoked_key_count', 'target_user_id',
        'target_api_key_id', 'owner_user_id', 'api_key_id', 'changed_at',
    ];

    public static function getNavigationLabel(): string
    {
        return 'Audit Logs';
    }

    public static function getModelLabel(): string
    {
        return 'Audit Log';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Audit Logs';
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
                TextEntry::make('action')
                    ->label('Event'),
                TextEntry::make('user.name')
                    ->label('Actor name')
                    ->placeholder('—'),
                TextEntry::make('user.email')
                    ->label('Actor email')
                    ->placeholder('—'),
                TextEntry::make('auditable_type')
                    ->label('Subject type')
                    ->formatStateUsing(fn (?string $state): string => self::formatSubjectType($state))
                    ->placeholder('—'),
                TextEntry::make('auditable_id')
                    ->label('Subject ID')
                    ->placeholder('—'),
                TextEntry::make('old_values_display')
                    ->label('Old values')
                    ->state(fn (AuditLog $record): ?string => self::formatJsonState($record->old_values))
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('new_values_display')
                    ->label('New values')
                    ->state(fn (AuditLog $record): ?string => self::formatJsonState($record->new_values))
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('metadata_display')
                    ->label('Metadata')
                    ->state(fn (AuditLog $record): ?string => self::formatJsonState($record->metadata))
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('ip_address')
                    ->label('IP address')
                    ->placeholder('—'),
                TextEntry::make('user_agent')
                    ->label('User agent')
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->label('Created')
                    ->dateTime(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('action')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('action')
                    ->label('Event')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Actor')
                    ->placeholder('—')
                    ->description(fn (AuditLog $record): ?string => $record->user?->email)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('user', function (Builder $userQuery) use ($search): void {
                            $userQuery
                                ->where('email', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        });
                    }),
                TextColumn::make('auditable_type')
                    ->label('Subject type')
                    ->formatStateUsing(fn (?string $state): string => self::formatSubjectType($state))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('auditable_id')
                    ->label('Subject ID')
                    ->placeholder('—')
                    ->limit(36)
                    ->toggleable(),
                TextColumn::make('source')
                    ->label('Source')
                    ->state(fn (AuditLog $record): string => (string) ($record->metadata['source'] ?? '—'))
                    ->badge(),
                TextColumn::make('target')
                    ->label('Target')
                    ->state(fn (AuditLog $record): string => self::targetIdentifier($record)),
                TextColumn::make('revoked_key_count')
                    ->label('Revoked keys')
                    ->state(fn (AuditLog $record): string => isset($record->metadata['revoked_key_count']) ? (string) $record->metadata['revoked_key_count'] : '—'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ip_address')
                    ->label('IP address')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->label('Event')
                    ->options(fn (): array => AuditLog::query()
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->all())
                    ->searchable(),
                SelectFilter::make('user_id')
                    ->label('Actor')
                    ->relationship('user', 'email')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('auditable_type')
                    ->label('Auditable type')
                    ->options(fn (): array => AuditLog::query()->whereNotNull('auditable_type')->distinct()->pluck('auditable_type', 'auditable_type')->mapWithKeys(fn (string $type): array => [ $type => class_basename($type) ])->all()),
                SelectFilter::make('source')
                    ->label('Source')
                    ->options(['api' => 'API', 'filament' => 'Filament'])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->whereJsonContains('metadata->source', $data['value']) : $query),
                Filter::make('target_id')
                    ->label('Target ID')
                    ->form([\Filament\Forms\Components\TextInput::make('value')->label('Target ID')])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('auditable_id', $data['value']) : $query),
                SelectFilter::make('revoked_key_count')
                    ->label('Revoked key count')
                    ->options(['present' => 'Present', 'absent' => 'Absent'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) { 'present' => $query->whereRaw("json_extract(metadata, '$.revoked_key_count') IS NOT NULL"), 'absent' => $query->where(fn (Builder $q) => $q->whereNull('metadata')->orWhereRaw("json_extract(metadata, '$.revoked_key_count') IS NULL")), default => $query }),
                Filter::make('created_at')
                    ->label('Created date')
                    ->schema([
                        DatePicker::make('created_from')
                            ->label('From'),
                        DatePicker::make('created_until')
                            ->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'] ?? null,
                                fn (Builder $query, mixed $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'] ?? null,
                                fn (Builder $query, mixed $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
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
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
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
            if (is_string($key) && (self::isSensitiveKey($key) || ! in_array($key, self::SAFE_KEYS, true))) {
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

        $encoded = json_encode(
            self::redactSensitive($state),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return $encoded;
    }

    private static function isSensitiveKey(string $key): bool
    {
        return in_array(strtolower($key), self::SENSITIVE_KEYS, true);
    }

    private static function targetIdentifier(AuditLog $record): string
    {
        foreach (['target_user_id', 'target_api_key_id', 'api_key_id', 'owner_user_id'] as $key) {
            if (isset($record->metadata[$key])) return (string) $record->metadata[$key];
        }
        return (string) ($record->auditable_id ?? '—');
    }

    private static function formatSubjectType(?string $state): string
    {
        if ($state === null || $state === '') {
            return '—';
        }

        return class_basename($state);
    }
}
