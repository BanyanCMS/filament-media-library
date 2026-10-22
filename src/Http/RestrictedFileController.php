<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Http;

use Banyan\FilamentMediaLibrary\Access\FolderAccess;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Storage\MediaRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * GET /media/{uuid}/restricted-file — a file in a members-only or password folder.
 *
 * Behind `signed` (a short-lived address, issued only to someone who could
 * browse the folder) and the host's session middleware (`delivery.restricted_middleware`,
 * `web` by default). On top of the signature the request must come from a signed-in
 * user who passes {@see FolderAccess::canBrowse()} *now*: a leaked address fails
 * for a logged-out visitor, and for the same person once their membership is
 * removed or the folder is relocked. Every refusal is a 404 with an empty body,
 * so the answer does not confirm that the file exists.
 */
final class RestrictedFileController
{
    public function __invoke(Request $request, string $uuid, MediaRepository $media, FolderAccess $access, FileController $files): SymfonyResponse
    {
        $asset = MediaAsset::findByUuid($uuid);
        $user = $request->user();

        if (! $asset instanceof MediaAsset || $user === null || ! $access->canBrowse($asset, $user)) {
            return new Response('', SymfonyResponse::HTTP_NOT_FOUND);
        }

        return $files->serve($request, $asset, $media, private: true);
    }
}
