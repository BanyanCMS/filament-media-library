<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\MediaAudit;

use Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\MediaAudit\Pages\ListMediaAudit;
use Banyan\FilamentMediaLibrary\Examples\Articles\Models\MediaAuditEntry;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** A read-only view of the audit trail: no create, edit or delete pages, and the policy-free resource refuses them. */
class MediaAuditResource extends Resource
{
    protected static ?string $model = MediaAuditEntry::class;

    protected static ?string $slug = 'media-audit';

    protected static ?string $modelLabel = 'media audit entry';

    protected static ?string $pluralModelLabel = 'Media audit log';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime(),
                TextColumn::make('event')->badge(),
                TextColumn::make('actor_id')->label('Actor (user id)'),
                TextColumn::make('asset_id')->label('Asset id'),
                TextColumn::make('payload')->label('Payload')->state(fn (MediaAuditEntry $record): string => (string) json_encode($record->payload))->limit(64)->tooltip(fn (MediaAuditEntry $record): string => (string) json_encode($record->payload))->fontFamily('mono')->size('xs'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMediaAudit::route('/')];
    }
}
