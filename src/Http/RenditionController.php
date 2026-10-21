<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Http;

use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * GET /media/{uuid}/render?w=&h=&fit=
 *
 * Behind `signed` middleware: the query string is an instruction to spend CPU
 * and disk, so it has to be one this application issued. The signature is
 * checked before the asset is loaded, so an unsigned request never even reveals
 * whether a uuid exists.
 */
final class RenditionController
{
    public function __invoke(Request $request, string $uuid, RenditionService $renditions): SymfonyResponse
    {
        $asset = MediaAsset::query()->where('uuid', $uuid)->first();

        if (! $asset instanceof MediaAsset) {
            return new Response('', SymfonyResponse::HTTP_NOT_FOUND);
        }

        $spec = $renditions->spec(
            $this->dimension($request->query('w')),
            $this->dimension($request->query('h')),
            is_string($fit = $request->query('fit')) ? $fit : null,
        );

        $rendition = $renditions->render($asset, $spec);

        if ($rendition === null) {
            return new Response('', SymfonyResponse::HTTP_NOT_FOUND);
        }

        [$contents, $mediaType] = $rendition;

        return new Response($contents, SymfonyResponse::HTTP_OK, [
            'Content-Type' => $mediaType,
            // Immutable is honest here: the URL carries the version and the
            // focal point (`v`, signed with the rest), so a new file or a new
            // crop is a new address and this one never describes other pixels.
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Length' => (string) strlen($contents),
        ]);
    }

    private function dimension(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
