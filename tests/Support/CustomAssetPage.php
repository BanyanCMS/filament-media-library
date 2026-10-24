<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Tests\Support;

use Banyan\FilamentMediaLibrary\Pages\MediaAssetPage;

final class CustomAssetPage extends MediaAssetPage
{
    protected static ?string $slug = 'pictures/{asset}';
}
