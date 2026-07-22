<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MailServers;

use App\Filament\Admin\Resources\MailServers\Pages\ListMailServers;
use App\Filament\Admin\Resources\MailServers\Pages\ViewMailServer;
use App\Enums\MailProtocol;
use App\Enums\MailProvider;
use App\Http\Requests\MailServer\CreateMailServerRequest;
use App\Models\MailServer;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class MailServerResource extends Resource
{
    protected static ?string $model = MailServer::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;
    protected static string|UnitEnum|null $navigationGroup = 'Platform';
    protected static ?int $navigationSort = 40;

    public static function getNavigationLabel(): string { return 'Mail Servers'; }
    public static function getModelLabel(): string { return 'Mail Server'; }
    public static function getPluralModelLabel(): string { return 'Mail Servers'; }
    public static function shouldRegisterNavigation(): bool { return static::canViewAny(); }
    public static function form(Schema $schema): Schema
    {
        $rules = (new CreateMailServerRequest)->rules();
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100)->rules($rules['name']),
            TextInput::make('hostname')->required()->maxLength(255)->rules($rules['hostname']),
            TextInput::make('port')->label('Port')->numeric()->minValue(1)->maxValue(65535),
            Select::make('provider')->options(MailProvider::labels())->required()->rules($rules['provider']),
            Select::make('protocol')->options(MailProtocol::labels())->required()->rules($rules['protocol']),
            TextInput::make('pool_key')->nullable()->maxLength(255)->rules($rules['pool_key']),
            TextInput::make('max_inboxes')->numeric()->minValue(1)->nullable()->rules($rules['max_inboxes']),
            Toggle::make('is_active')->default(true)->rules($rules['is_active']),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('Name'),
            TextEntry::make('hostname')->label('Hostname'),
            TextEntry::make('port')->label('Port')->state(fn (MailServer $record): string => (string) ($record->metadata['port'] ?? '—')),
            TextEntry::make('protocol')->label('Protocol'),
            TextEntry::make('pool_key')->label('Pool key')->placeholder('—'),
            TextEntry::make('capacity')->label('Capacity')->state(fn (MailServer $record): string => self::capacity($record)),
            TextEntry::make('status')->label('Status')->state(fn (MailServer $record): string => $record->is_active ? 'Active' : 'Inactive')->badge(),
            TextEntry::make('health')->label('Health')->state(fn (MailServer $record): string => $record->healthy() ? 'Healthy' : 'Unhealthy')->badge(),
            TextEntry::make('last_health_check_at')->label('Last health check')->dateTime()->placeholder('—'),
            TextEntry::make('created_at')->label('Created')->dateTime(),
            TextEntry::make('updated_at')->label('Updated')->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->recordTitleAttribute('name')->defaultSort('priority', 'desc')->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('hostname')->searchable()->sortable(),
            TextColumn::make('protocol')->sortable(),
            TextColumn::make('pool_key')->placeholder('—')->sortable(),
            TextColumn::make('capacity')->state(fn (MailServer $record): string => self::capacity($record)),
            TextColumn::make('status')->state(fn (MailServer $record): string => $record->is_active ? 'Active' : 'Inactive')->badge(),
            TextColumn::make('health')->state(fn (MailServer $record): string => $record->healthy() ? 'Healthy' : 'Unhealthy')->badge(),
            TextColumn::make('last_health_check_at')->dateTime()->sortable(),
        ])->filters([
            TernaryFilter::make('is_active')->label('Active'),
            SelectFilter::make('health')->options(['healthy' => 'Healthy', 'unhealthy' => 'Unhealthy'])->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) { 'healthy' => $query->whereNotNull('last_health_check_at')->where('last_health_check_at', '>=', now()->subMinutes(10)), 'unhealthy' => $query->where(fn (Builder $q) => $q->whereNull('last_health_check_at')->orWhere('last_health_check_at', '<', now()->subMinutes(10))), default => $query }),
            SelectFilter::make('pool_key')->options(fn () => MailServer::query()->whereNotNull('pool_key')->distinct()->orderBy('pool_key')->pluck('pool_key', 'pool_key')->all()),
            SelectFilter::make('capacity')->options(['limited' => 'Limited', 'unlimited' => 'Unlimited'])->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) { 'limited' => $query->whereNotNull('max_inboxes'), 'unlimited' => $query->whereNull('max_inboxes'), default => $query }),
        ])->recordActions([ViewAction::make()])->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['inboxes as current_inboxes_count' => fn (Builder $q) => $q->whereNull('inboxes.deleted_at')->where('is_active', true)->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))]);
    }

    public static function getPages(): array { return ['index' => ListMailServers::route('/'), 'create' => Pages\CreateMailServer::route('/create'), 'view' => ViewMailServer::route('/{record}'), 'edit' => Pages\EditMailServer::route('/{record}/edit')]; }
    public static function capacity(MailServer $record): string { return $record->max_inboxes === null ? (($record->current_inboxes_count ?? 0).' / Unlimited') : (($record->current_inboxes_count ?? 0).' / '.$record->max_inboxes); }
}
