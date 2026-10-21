<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Tests\Support;

use Banyan\FilamentMediaLibrary\Admin\MediaAssetPage;

final class CustomAssetPage extends MediaAssetPage
{
    protected static ?string $slug = 'pictures/{asset}';
}
