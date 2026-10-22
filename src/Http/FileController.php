<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Http;

use Banyan\FilamentMediaLibrary\Delivery\FileDelivery;
use Banyan\FilamentMediaLibrary\Models\MediaAsset;
use Banyan\FilamentMediaLibrary\Storage\MediaRepository;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * GET /media/{uuid}/file[?download=1]
 *
 * Serves the asset's current file behind `signed` middleware, checked before
 * the asset is loaded so an unsigned request never reveals whether a uuid exists.
 *
 * Whatever the transport, the answer is the same: the *stored, detected* type
 * (never one guessed from the request), `nosniff`, and an inline or attachment
 * disposition decided by the kind. A stored `text/html` can never exist (the
 * upload guard refuses it), but `nosniff` makes the stored type binding anyway.
 *
 * Three transports, picked by what the disk can do:
 *  - a local disk: {@see BinaryFileResponse}, which implements `Range`, so video and audio seek;
 *  - a disk with temporary URLs (S3, R2): a 302 to one, valid for
 *    `delivery.temporary_url_minutes`, with the type and disposition passed as
 *    response overrides, so the provider does the ranging;
 *  - anything else: the whole file, streamed.
 */
final class FileController
{
    public function __invoke(Request $request, string $uuid, MediaRepository $media): SymfonyResponse
    {
        $asset = MediaAsset::findByUuid($uuid);

        // A restricted file is never served here, whatever the signature says: this route has no
        // session, so it cannot know who is asking. Not found, not forbidden, so nothing is confirmed.
        if (! $asset instanceof MediaAsset || $asset->isRestricted()) {
            return new Response('', SymfonyResponse::HTTP_NOT_FOUND);
        }

        return $this->serve($request, $asset, $media, private: false);
    }

    /**
     * Send the file. `$private` is for restricted files: the answer is for this
     * visitor only, so no shared cache and no year-long immutable header.
     */
    public function serve(Request $request, MediaAsset $asset, MediaRepository $media, bool $private): SymfonyResponse
    {
        $disk = $media->diskFor($asset);

        if (! $disk->exists($asset->path)) {
            return new Response('', SymfonyResponse::HTTP_NOT_FOUND);
        }

        $disposition = FileDelivery::disposition($asset, $request->boolean('download'));
        $headers = [
            'Content-Type' => $asset->mime_type,
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
            // Immutable is honest: the URL carries the version, signed with the rest.
            'Cache-Control' => $private ? 'private, no-store' : 'public, max-age=31536000, immutable',
        ];

        if (! $disk instanceof FilesystemAdapter) {
            return $this->stream($asset, $disk, $headers);
        }

        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return new BinaryFileResponse($disk->path($asset->path), SymfonyResponse::HTTP_OK, $headers, ! $private, null, false, true);
        }

        if ($disk->providesTemporaryUrls()) {
            return $this->redirect($asset, $disk, $headers);
        }

        return $this->stream($asset, $disk, $headers);
    }

    /** @param array<string, string> $headers */
    private function redirect(MediaAsset $asset, FilesystemAdapter $disk, array $headers): SymfonyResponse
    {
        $url = $disk->temporaryUrl(
            $asset->path,
            now()->addMinutes(max(1, MediaConfig::int('delivery.temporary_url_minutes', 5))),
            [
                'ResponseContentType' => $headers['Content-Type'],
                'ResponseContentDisposition' => $headers['Content-Disposition'],
            ],
        );

        // Not cached: the target expires in minutes, the address that issued it does not.
        return new RedirectResponse($url, SymfonyResponse::HTTP_FOUND, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array<string, string> $headers */
    private function stream(MediaAsset $asset, Filesystem $disk, array $headers): SymfonyResponse
    {
        return new StreamedResponse(static function () use ($asset, $disk): void {
            try {
                $stream = $disk->readStream($asset->path);

                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            } catch (Throwable) {
                // The headers are out; there is nothing left to tell the client but a short body.
            }
        }, SymfonyResponse::HTTP_OK, $headers + ['Content-Length' => (string) $asset->size_bytes]);
    }
}
