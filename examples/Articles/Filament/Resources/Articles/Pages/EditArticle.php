<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\Articles\Pages;

use Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\Articles\ArticleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
