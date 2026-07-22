<?php
declare(strict_types=1);
namespace App\Filament\Admin\Resources\ApiRequestLogs;
use App\Filament\Admin\Resources\ApiRequestLogs\Pages\ListApiRequestLogs;
use App\Filament\Admin\Resources\ApiRequestLogs\Pages\ViewApiRequestLog;
use App\Models\ApiRequestLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ApiRequestLogResource extends Resource
{
    protected static ?string $model = ApiRequestLog::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;
    protected static string|UnitEnum|null $navigationGroup = 'Platform';
    protected static ?int $navigationSort = 50;
    private const SAFE_METADATA = ['was_throttled'];

    public static function getNavigationLabel(): string { return 'API Request Logs'; }
    public static function getModelLabel(): string { return 'API Request Log'; }
    public static function getPluralModelLabel(): string { return 'API Request Logs'; }
    public static function shouldRegisterNavigation(): bool { return static::canViewAny(); }
    public static function form(Schema $schema): Schema { return $schema->components([]); }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')->label('Timestamp')->dateTime(),
            TextEntry::make('method'), TextEntry::make('endpoint')->label('Route/path'),
            TextEntry::make('response_status')->label('Status')->badge(),
            TextEntry::make('response_time_ms')->label('Duration (ms)'),
            TextEntry::make('ip_address')->label('IP address'),
            TextEntry::make('api_key_id')->label('API key UUID')->placeholder('—'),
            TextEntry::make('user.email')->label('Owner')->placeholder('—'),
            TextEntry::make('user_id')->label('Owner UUID')->placeholder('—'),
            TextEntry::make('request_size_bytes')->label('Request bytes')->placeholder('—'),
            TextEntry::make('response_size_bytes')->label('Response bytes')->placeholder('—'),
            TextEntry::make('throttled')->label('Throttled')->state(fn (ApiRequestLog $record): string => self::throttled($record) ? 'Yes' : 'No')->badge(),
            TextEntry::make('safe_metadata')->label('Operational flags')->state(fn (ApiRequestLog $record): string => self::safeMetadata($record)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->recordTitleAttribute('endpoint')->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('created_at')->label('Timestamp')->dateTime()->sortable(),
            TextColumn::make('method')->sortable(),
            TextColumn::make('endpoint')->label('Route/path')->searchable()->sortable(),
            TextColumn::make('response_status')->label('Status')->badge()->sortable(),
            TextColumn::make('response_time_ms')->label('Duration')->suffix(' ms')->sortable(),
            TextColumn::make('ip_address'),
            TextColumn::make('api_key_id')->label('API key UUID')->searchable()->toggleable(),
            TextColumn::make('user.email')->label('Owner')->searchable(query: fn (Builder $q, string $s): Builder => $q->whereHas('user', fn (Builder $u): Builder => $u->where('email', 'like', "%{$s}%"))),
            TextColumn::make('throttled')->label('Throttled')->state(fn (ApiRequestLog $record): string => self::throttled($record) ? 'Yes' : 'No')->badge(),
        ])->filters([
            SelectFilter::make('response_status')->label('Status code')->options(fn (): array => ApiRequestLog::query()->distinct()->orderBy('response_status')->pluck('response_status', 'response_status')->all()),
            SelectFilter::make('method')->options(['GET'=>'GET','POST'=>'POST','PUT'=>'PUT','PATCH'=>'PATCH','DELETE'=>'DELETE']),
            SelectFilter::make('endpoint')->label('Route/path')->options(fn (): array => ApiRequestLog::query()->distinct()->orderBy('endpoint')->pluck('endpoint', 'endpoint')->all())->searchable(),
            SelectFilter::make('api_key_id')->label('API key ID')->options(fn (): array => ApiRequestLog::query()->whereNotNull('api_key_id')->distinct()->pluck('api_key_id', 'api_key_id')->all())->searchable(),
            SelectFilter::make('user_id')->label('Owner')->relationship('user', 'email')->searchable()->preload(),
            TernaryFilter::make('throttled')->label('Throttled')->queries(true: fn (Builder $q): Builder => $q->whereJsonContains('metadata->was_throttled', true), false: fn (Builder $q): Builder => $q->where(fn (Builder $x) => $x->whereNull('metadata')->orWhereJsonDoesntContain('metadata->was_throttled', true))),
            SelectFilter::make('status_family')->options(['2xx'=>'2xx Success','4xx'=>'4xx Client error','5xx'=>'5xx Server error'])->query(fn (Builder $q, array $data): Builder => match ($data['value'] ?? null) { '2xx'=>$q->whereBetween('response_status',[200,299]), '4xx'=>$q->whereBetween('response_status',[400,499]), '5xx'=>$q->whereBetween('response_status',[500,599]), default=>$q }),
            Filter::make('created_at')->label('Date range')->schema([DatePicker::make('from'), DatePicker::make('until')])->query(fn (Builder $q, array $data): Builder => $q->when($data['from'] ?? null, fn (Builder $x, $v): Builder => $x->whereDate('created_at','>=',$v))->when($data['until'] ?? null, fn (Builder $x, $v): Builder => $x->whereDate('created_at','<=',$v))),
            Filter::make('duration')->label('Duration threshold')->schema([TextInput::make('min')->numeric()->label('Minimum ms')])->query(fn (Builder $q, array $data): Builder => filled($data['min'] ?? null) ? $q->where('response_time_ms','>=',(int)$data['min']) : $q),
        ])->recordActions([ViewAction::make()])->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder { return parent::getEloquentQuery()->with(['apiKey','user']); }
    public static function getRelations(): array { return []; }
    public static function getPages(): array { return ['index'=>ListApiRequestLogs::route('/'), 'view'=>ViewApiRequestLog::route('/{record}')]; }
    public static function throttled(ApiRequestLog $record): bool { return ($record->metadata['was_throttled'] ?? false) === true; }
    public static function safeMetadata(ApiRequestLog $record): string { return self::throttled($record) ? 'was_throttled: true' : '—'; }
}
