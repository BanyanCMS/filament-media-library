<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Http;

use Banyan\FilamentMediaLibrary\Delivery\DeliveryRoutes;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Rendering\PdfThumbnailService;
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
    public function __invoke(Request $request, string $uuid, RenditionService $renditions, PdfThumbnailService $pdfs): SymfonyResponse
    {
        $asset = MediaAsset::findByUuid($uuid);

        // Restricted files are served only by the session-checked route; see FileController.
        if (! $asset instanceof MediaAsset || $asset->isRestricted()) {
            return new Response('', SymfonyResponse::HTTP_NOT_FOUND);
        }

        return $this->serve($request, $asset, $renditions, $pdfs, private: false);
    }

    /** Send the rendition; `$private` marks a restricted file's answer as not cacheable by anyone else. */
    public function serve(Request $request, MediaAsset $asset, RenditionService $renditions, PdfThumbnailService $pdfs, bool $private): SymfonyResponse
    {
        $cache = DeliveryRoutes::cacheControl($private);

        // A PDF has no pixels to crop, but the grid may ask for its first page.
        if ($asset->isPdf()) {
            $png = $pdfs->render($asset, $this->dimension($request->query('w')));

            return $png === null ? new Response('', SymfonyResponse::HTTP_NOT_FOUND) : new Response($png, SymfonyResponse::HTTP_OK, [
                'Content-Type' => 'image/png',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => $cache,
            ]);
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
            'Cache-Control' => $cache,
            'Content-Length' => (string) strlen($contents),
        ]);
    }

    private function dimension(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
