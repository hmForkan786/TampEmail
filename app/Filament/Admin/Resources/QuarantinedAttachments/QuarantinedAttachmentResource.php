<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\QuarantinedAttachments;

use App\Enums\AttachmentScanStatus;
use App\Filament\Admin\Resources\QuarantinedAttachments\Pages\ListQuarantinedAttachments;
use App\Filament\Admin\Resources\QuarantinedAttachments\Pages\ViewQuarantinedAttachment;
use App\Models\Attachment;
use App\Models\User;
use App\Policies\AttachmentPolicy;
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

class QuarantinedAttachmentResource extends Resource
{
    protected static ?string $model = Attachment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 41;

    public static function getNavigationLabel(): string
    {
        return 'Quarantined Attachments';
    }

    public static function getModelLabel(): string
    {
        return 'Quarantined Attachment';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Quarantined Attachments';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(AttachmentPolicy::class)->viewAny($actor);
    }

    public static function canView($record): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && $record instanceof Attachment
            && app(AttachmentPolicy::class)->view($actor, $record);
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
            TextEntry::make('id')->label('Attachment ID'),
            TextEntry::make('email_id')->label('Email ID'),
            TextEntry::make('original_filename')->label('Filename'),
            TextEntry::make('mime_type')->label('MIME type'),
            TextEntry::make('size_bytes')->label('Size (bytes)'),
            TextEntry::make('checksum_sha256')->label('Checksum'),
            TextEntry::make('scan_status')->label('Scan status')->formatStateUsing(
                fn (?AttachmentScanStatus $state): ?string => $state?->value
            )->badge(),
            TextEntry::make('is_safe')->label('Safe')->formatStateUsing(
                fn (?bool $state): string => $state === true ? 'yes' : ($state === false ? 'no' : 'unknown')
            ),
            TextEntry::make('threat_label')->label('Threat label')->state(
                fn (Attachment $record): ?string => self::threatLabel($record)
            )->placeholder('—'),
            TextEntry::make('scan_error')->label('Scan error')->state(
                fn (Attachment $record): ?string => self::scanError($record)
            )->placeholder('—'),
            TextEntry::make('scanned_at')->label('Scanned at')->dateTime()->placeholder('—'),
            TextEntry::make('created_at')->label('Created')->dateTime(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('scanned_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('Attachment ID')->searchable()->limit(36),
                TextColumn::make('email_id')->label('Email ID')->searchable()->limit(36),
                TextColumn::make('original_filename')->label('Filename')->searchable()->limit(40),
                TextColumn::make('scan_status')->label('Status')->formatStateUsing(
                    fn (?AttachmentScanStatus $state): ?string => $state?->value
                )->badge()->sortable(),
                TextColumn::make('threat_label')->label('Threat')->state(
                    fn (Attachment $record): string => self::threatLabel($record) ?? '—'
                ),
                TextColumn::make('size_bytes')->label('Bytes')->sortable(),
                TextColumn::make('scanned_at')->label('Scanned')->dateTime()->sortable(),
                TextColumn::make('created_at')->label('Created')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('scan_status')->options([
                    AttachmentScanStatus::Infected->value => 'Infected',
                    AttachmentScanStatus::Failed->value => 'Failed',
                ]),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->quarantined()
            ->with('email');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuarantinedAttachments::route('/'),
            'view' => ViewQuarantinedAttachment::route('/{record}'),
        ];
    }

    public static function threatLabel(Attachment $record): ?string
    {
        $raw = $record->metadata['malware_signature'] ?? $record->metadata['threat_label'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $safe = preg_replace('/[^A-Za-z0-9._:+ -]/', '', $raw) ?: '';

        return $safe === '' ? null : mb_substr($safe, 0, 120);
    }

    public static function scanError(Attachment $record): ?string
    {
        $raw = $record->metadata['scan_error'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $safe = preg_replace('/[^A-Za-z0-9._:-]/', '', $raw) ?: '';

        return $safe === '' ? null : mb_substr($safe, 0, 80);
    }
}
