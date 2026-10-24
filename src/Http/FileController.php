<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Http;

use Banyan\FilamentMediaLibrary\Delivery\DeliveryRoutes;
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
 *    response overrides, so the provider does the ranging, but only for a
 *    download or a top-level visit. An embed (the PDF iframe, a <video> or
 *    <audio>, which the browser marks with `Sec-Fetch-Dest`) is streamed from
 *    this origin instead: a strict Content Security Policy (`frame-src 'self'`,
 *    `media-src 'self'`) would block a redirect to the storage host, and
 *    widening a CSP is a security decision a media library must not force;
 *  - anything else: the file streamed from this origin, honouring a single `Range`.
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
            'Cache-Control' => DeliveryRoutes::cacheControl($private),
        ];

        if (! $disk instanceof FilesystemAdapter) {
            return $this->stream($request, $asset, $disk, $headers);
        }

        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return new BinaryFileResponse($disk->path($asset->path), SymfonyResponse::HTTP_OK, $headers, ! $private, null, false, true);
        }

        // Both answers can come from one URL, so a shared cache must key on the header that chose.
        $headers['Vary'] = 'Sec-Fetch-Dest';

        if ($disk->providesTemporaryUrls() && $this->mayRedirect($request)) {
            return $this->redirect($asset, $disk, $headers);
        }

        return $this->stream($request, $asset, $disk, $headers);
    }

    /** Elements that load a resource in place, under the page's CSP. */
    private const array EMBEDDING_DESTINATIONS = ['iframe', 'frame', 'embed', 'object', 'video', 'audio', 'track'];

    /**
     * `delivery.redirect_to_storage`: `downloads` (default) redirects everything
     * except embeds, `always` redirects everything, `never` always streams.
     */
    private function mayRedirect(Request $request): bool
    {
        return match (MediaConfig::string('delivery.redirect_to_storage', 'downloads')) {
            'always' => true,
            'never' => false,
            default => ! in_array(strtolower((string) $request->headers->get('Sec-Fetch-Dest', '')), self::EMBEDDING_DESTINATIONS, true),
        };
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
            'Cache-Control' => DeliveryRoutes::PRIVATE_CACHE,
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Sec-Fetch-Dest',
        ]);
    }

    /**
     * Stream from the disk through this origin, honouring one `Range` so a
     * <video> or <audio> can seek. A non-seekable remote stream is read
     * forward to the start of the range: correct, if slower for a far seek.
     *
     * @param  array<string, string>  $headers
     */
    private function stream(Request $request, MediaAsset $asset, Filesystem $disk, array $headers): SymfonyResponse
    {
        $size = max(0, $asset->size_bytes);
        $range = $this->range($request->headers->get('Range'), $size);
        $headers['Accept-Ranges'] = 'bytes';

        if ($range === false) {
            return new Response('', SymfonyResponse::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, ['Content-Range' => 'bytes */'.$size] + $headers);
        }

        [$start, $end] = $range ?? [0, max(0, $size - 1)];
        $length = $size === 0 ? 0 : $end - $start + 1;
        $status = $range === null ? SymfonyResponse::HTTP_OK : SymfonyResponse::HTTP_PARTIAL_CONTENT;
        $headers['Content-Length'] = (string) $length;

        if ($range !== null) {
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
        }

        return new StreamedResponse(static function () use ($asset, $disk, $start, $length): void {
            try {
                $stream = $disk->readStream($asset->path);

                if (! is_resource($stream)) {
                    return;
                }

                if ($start > 0 && (! stream_get_meta_data($stream)['seekable'] || fseek($stream, $start) !== 0)) {
                    $skip = $start;

                    while ($skip > 0 && ! feof($stream)) {
                        $chunk = fread($stream, min(1048576, $skip));
                        $skip -= $chunk === false ? $skip : strlen($chunk);
                    }
                }

                $left = $length;

                while ($left > 0 && ! feof($stream)) {
                    $chunk = fread($stream, min(1048576, $left));

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    echo $chunk;
                    $left -= strlen($chunk);
                }

                fclose($stream);
            } catch (Throwable) {
                // The headers are out; there is nothing left to tell the client but a short body.
            }
        }, $status, $headers);
    }

    /**
     * One `bytes=` range, as [start, end] inclusive; null when absent or not a
     * single byte range (served whole, as RFC 9110 allows); false when unsatisfiable.
     *
     * @return array{0: int, 1: int}|false|null
     */
    private function range(?string $header, int $size): array|false|null
    {
        if ($header === null || preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
            return null;
        }

        if ($m[1] === '') {
            $suffix = (int) $m[2];

            return $suffix === 0 || $size === 0 ? false : [max(0, $size - $suffix), $size - 1];
        }

        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);

        return $start >= $size || $end < $start ? false : [$start, $end];
    }
}
