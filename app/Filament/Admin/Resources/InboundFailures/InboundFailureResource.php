<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InboundFailures;

use App\Enums\ProcessingLogStatus;
use App\Enums\ProcessingStage;
use App\Filament\Admin\Resources\InboundFailures\Pages\ListInboundFailures;
use App\Filament\Admin\Resources\InboundFailures\Pages\ViewInboundFailure;
use App\Models\AuditLog;
use App\Models\EmailProcessingLog;
use App\Models\User;
use App\Policies\InboundFailurePolicy;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class InboundFailureResource extends Resource
{
    protected static ?string $model = EmailProcessingLog::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;
    protected static string|UnitEnum|null $navigationGroup = 'Platform';
    protected static ?int $navigationSort = 40;

    public static function getNavigationLabel(): string { return 'Inbound Failures'; }
    public static function getModelLabel(): string { return 'Inbound Failure'; }
    public static function getPluralModelLabel(): string { return 'Inbound Failures'; }
    public static function shouldRegisterNavigation(): bool { return static::canViewAny(); }
    public static function canViewAny(): bool
    {
        $actor = auth()->user();
        return $actor instanceof User && app(InboundFailurePolicy::class)->viewAny($actor);
    }
    public static function canView($record): bool
    {
        $actor = auth()->user();
        return $actor instanceof User && $record instanceof EmailProcessingLog && app(InboundFailurePolicy::class)->view($actor, $record);
    }
    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }
    public static function canDelete($record): bool { return false; }
    public static function form(Schema $schema): Schema { return $schema->components([]); }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id')->label('Failure ID'),
            TextEntry::make('stage')->formatStateUsing(fn (?ProcessingStage $state): ?string => $state?->value),
            TextEntry::make('failure_code')->label('Failure code')->state(fn (EmailProcessingLog $record): string => self::failureCode($record)),
            TextEntry::make('operational_status')->label('Status')->state(fn (EmailProcessingLog $record): string => self::operationalStatus($record))->badge(),
            TextEntry::make('safe_message')->label('Message')->state(fn (EmailProcessingLog $record): ?string => self::safeMessage($record))->placeholder('—'),
            TextEntry::make('email.message_id')->label('Message ID')->placeholder('—'),
            TextEntry::make('email_id')->label('Email ID'),
            TextEntry::make('attachment_id')->label('Attachment ID')->state(fn (EmailProcessingLog $record): ?string => self::attachmentId($record))->placeholder('—'),
            TextEntry::make('attempt_count')->label('Attempts')->state(fn (EmailProcessingLog $record): string => (string) self::attempts($record)),
            TextEntry::make('failed_at')->label('Failed at')->state(fn (EmailProcessingLog $record): ?string => self::failedAt($record))->placeholder('—'),
            TextEntry::make('retryable_at')->label('Retryable at')->state(fn (EmailProcessingLog $record): ?string => self::retryableAt($record))->placeholder('—'),
            TextEntry::make('replay_available')->label('Replay available')->state(fn (EmailProcessingLog $record): string => self::replayAvailable($record) ? 'Yes' : 'No'),
            TextEntry::make('created_at')->label('Created')->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->recordTitleAttribute('id')->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('id')->label('Failure ID')->searchable()->limit(36),
            TextColumn::make('stage')->formatStateUsing(fn (?ProcessingStage $state): ?string => $state?->value)->sortable(),
            TextColumn::make('failure_code')->label('Failure code')->state(fn (EmailProcessingLog $record): string => self::failureCode($record))->searchable(query: fn (Builder $query, string $search): Builder => $query->where('metadata->failure_code', 'like', "%{$search}%")),
            TextColumn::make('operational_status')->label('Status')->state(fn (EmailProcessingLog $record): string => self::operationalStatus($record))->badge(),
            TextColumn::make('email.message_id')->label('Message ID')->searchable(),
            TextColumn::make('email_id')->label('Email ID')->searchable(),
            TextColumn::make('attachment_id')->label('Attachment ID')->state(fn (EmailProcessingLog $record): string => self::attachmentId($record) ?? '—')->searchable(query: fn (Builder $q, string $search): Builder => $q->where('metadata->attachment_id', 'like', "%{$search}%")),
            TextColumn::make('attempt_count')->label('Attempts')->state(fn (EmailProcessingLog $record): string => (string) self::attempts($record))->sortable(),
            TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('stage')->options(collect(ProcessingStage::cases())->mapWithKeys(fn (ProcessingStage $stage): array => [$stage->value => $stage->value])->all()),
            SelectFilter::make('status')->options(collect(ProcessingLogStatus::cases())->mapWithKeys(fn (ProcessingLogStatus $status): array => [$status->value => $status->value])->all()),
            SelectFilter::make('operational_status')->options(['retryable' => 'Retryable', 'permanent' => 'Permanent', 'replayed' => 'Replayed'])->query(fn (Builder $q, array $data): Builder => self::statusQuery($q, $data['value'] ?? null)),
            Filter::make('created_at')->label('Date range')->schema([DatePicker::make('from'), DatePicker::make('until')])->query(fn (Builder $q, array $data): Builder => $q->when($data['from'] ?? null, fn (Builder $x, $v): Builder => $x->whereDate('created_at', '>=', $v))->when($data['until'] ?? null, fn (Builder $x, $v): Builder => $x->whereDate('created_at', '<=', $v))),
            Filter::make('attempts')->label('Attempt count')->schema([TextInput::make('min')->numeric(), TextInput::make('max')->numeric()])->query(fn (Builder $q, array $data): Builder => $q->when($data['min'] ?? null, fn (Builder $x, $v): Builder => $x->where('metadata->attempts', '>=', (int) $v))->when($data['max'] ?? null, fn (Builder $x, $v): Builder => $x->where('metadata->attempts', '<=', (int) $v))),
        ])->recordActions([ViewAction::make()])->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder { return parent::getEloquentQuery()->with('email'); }
    public static function getRelations(): array { return []; }
    public static function getPages(): array { return ['index' => ListInboundFailures::route('/'), 'view' => ViewInboundFailure::route('/{record}')]; }
    public static function failureCode(EmailProcessingLog $record): string { return (string) ($record->metadata['failure_code'] ?? '—'); }
    public static function attempts(EmailProcessingLog $record): int { return max(0, (int) ($record->metadata['attempts'] ?? 0)); }
    public static function attachmentId(EmailProcessingLog $record): ?string { return isset($record->metadata['attachment_id']) ? (string) $record->metadata['attachment_id'] : null; }
    public static function failedAt(EmailProcessingLog $record): ?string { return isset($record->metadata['failed_at']) ? substr((string) $record->metadata['failed_at'], 0, 40) : null; }
    public static function retryableAt(EmailProcessingLog $record): ?string { return isset($record->metadata['retryable_at']) ? substr((string) $record->metadata['retryable_at'], 0, 40) : null; }
    public static function replayAvailable(EmailProcessingLog $record): bool { return $record->status === ProcessingLogStatus::Failed && $record->stage === ProcessingStage::Scan && self::attachmentId($record) !== null; }
    public static function operationalStatus(EmailProcessingLog $record): string
    {
        if (AuditLog::query()->where('action', 'inbound.failure_replayed')->where('auditable_id', $record->email_id)->exists()) return 'Replayed';
        if (($record->metadata['retryable'] ?? null) === true) return 'Retryable';
        if (($record->metadata['retryable'] ?? null) === false) return 'Permanent';
        return $record->status?->value ?? 'Unknown';
    }
    public static function safeMessage(EmailProcessingLog $record): ?string
    {
        $message = trim(strip_tags((string) $record->error_message));
        $message = preg_replace('/(?:authorization|token|hash|password|secret|trace|command|path)\s*[:=].*/i', '[redacted]', $message) ?: '';
        return $message === '' ? null : mb_substr($message, 0, 240);
    }
    private static function statusQuery(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            'retryable' => $query->where('metadata->retryable', true),
            'permanent' => $query->where('metadata->retryable', false),
            'replayed' => $query->whereIn('email_id', AuditLog::query()->where('action', 'inbound.failure_replayed')->select('auditable_id')),
            default => $query,
        };
    }
}
