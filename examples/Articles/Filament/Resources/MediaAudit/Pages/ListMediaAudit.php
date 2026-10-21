<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\MediaAudit\Pages;

use Banyan\FilamentMediaLibrary\Examples\Articles\Filament\Resources\MediaAudit\MediaAuditResource;
use Filament\Resources\Pages\ListRecords;

class ListMediaAudit extends ListRecords
{
    protected static string $resource = MediaAuditResource::class;
}
