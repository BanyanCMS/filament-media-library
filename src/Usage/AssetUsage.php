<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Usage;

/**
 * One place an asset is used, as the asset page lists it.
 *
 * `label` names the owner ("Post #12"), `type` what kind of thing it is,
 * `field` which of its fields holds the asset. `url`, when set, links the row.
 */
final readonly class AssetUsage
{
    public function __construct(
        public string $label,
        public string $type,
        public string $field,
        public ?string $locale = null,
        public ?string $url = null,
    ) {}
}
