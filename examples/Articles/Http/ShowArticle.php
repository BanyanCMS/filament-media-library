<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Examples\Articles\Http;

use Banyan\FilamentMediaLibrary\Examples\Articles\Models\Article;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

/** The public page: renditions are asked for by size, the library crops around the focal point. */
final class ShowArticle
{
    public function __invoke(Article $article, RenditionService $renditions, Factory $views): View
    {
        /** @var view-string $view */
        $view = 'example-articles::article';
        $hero = $article->hero();

        return $views->make($view, [
            'article' => $article,
            'hero' => $hero,
            // 1200x630 is the Open Graph size; the smaller ones feed srcset.
            'heroSrc' => $hero === null ? null : $renditions->url($hero, 1200, 630, 'crop'),
            'heroSrcset' => $hero === null ? null : implode(', ', [
                $renditions->url($hero, 600, 315, 'crop').' 600w',
                $renditions->url($hero, 1200, 630, 'crop').' 1200w',
            ]),
            'gallery' => MediaAsset::query()->whereIn('id', $article->assetIds('gallery'))->get()
                ->map(fn (MediaAsset $asset): array => ['alt' => $asset->alt_text, 'src' => $renditions->url($asset, 400, 300, 'crop')]),
        ]);
    }
}
