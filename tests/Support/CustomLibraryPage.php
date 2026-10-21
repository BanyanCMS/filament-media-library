<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Tests\Support;

use Banyan\FilamentMediaLibrary\Admin\MediaLibraryPage;

final class CustomLibraryPage extends MediaLibraryPage
{
    protected static ?string $slug = 'pictures';
}
