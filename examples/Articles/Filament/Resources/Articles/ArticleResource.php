<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\Articles;

use Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\Articles\Pages\CreateArticle;
use Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\Articles\Pages\EditArticle;
use Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\Articles\Pages\ListArticles;
use Banyan\FilamentMediaLibrary\Examples\Articles\Models\Article;
use Banyan\FilamentMediaLibrary\Forms\MediaPicker;
use Banyan\FilamentMediaLibrary\Rendering\MediaThumbnailColumn;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Articles with a hero image and a gallery, both picked from the media library. */
class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('title')->required()->maxLength(160),
            MediaPicker::make('hero_id')->label('Hero image')->thumbnails(),
            MediaPicker::make('gallery')->label('Gallery')->multiple()->thumbnails(),
            Textarea::make('body')->required()->rows(6),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                MediaThumbnailColumn::make('hero_id')->label('Hero')->thumbnailSize(96, 64),
                TextColumn::make('title')->searchable(),
                TextColumn::make('updated_at')->since(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArticles::route('/'),
            'create' => CreateArticle::route('/create'),
            'edit' => EditArticle::route('/{record}/edit'),
        ];
    }
}
