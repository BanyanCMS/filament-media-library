<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Http;

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Rendering\PdfThumbnailService;
use Banyan\FilamentMediaLibrary\Rendering\RenditionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * GET /media/{uuid}/restricted-render — a rendition of a file in a restricted folder.
 * The same checks as {@see RestrictedFileController}.
 */
final class RestrictedRenditionController
{
    public function __invoke(Request $request, string $uuid, RenditionService $renditions, PdfThumbnailService $pdfs, FolderAccess $access, RenditionController $controller): SymfonyResponse
    {
        $asset = MediaAsset::findByUuid($uuid);
        $user = $request->user();

        if (! $asset instanceof MediaAsset || $user === null || ! $access->canBrowse($asset, $user)) {
            return new Response('', SymfonyResponse::HTTP_NOT_FOUND);
        }

        return $controller->serve($request, $asset, $renditions, $pdfs, private: true);
    }
}
