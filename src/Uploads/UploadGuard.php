<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Uploads;

use Banyan\FilamentMediaLibrary\Contracts\FileScanner;
use Banyan\FilamentMediaLibrary\Enums\MediaKind;
use Banyan\FilamentMediaLibrary\Events\AssetUploading;
use Banyan\FilamentMediaLibrary\Exceptions\ExtensionMismatch;
use Banyan\FilamentMediaLibrary\Exceptions\FileTooLarge;
use Banyan\FilamentMediaLibrary\Exceptions\KindMismatch;
use Banyan\FilamentMediaLibrary\Exceptions\UnreadableImage;
use Banyan\FilamentMediaLibrary\Exceptions\UnsupportedFileType;
use Banyan\FilamentMediaLibrary\Exceptions\UploadRejected;
use Banyan\FilamentMediaLibrary\Rendering\ImageProcessor;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use Banyan\FilamentMediaLibrary\Support\MediaKinds;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use LogicException;
use RuntimeException;
use ZipArchive;

/**
 * Everything that must hold about the bytes before they are written.
 *
 * Trust nothing the client said. The type comes from the bytes, the extension
 * has to agree with it, and only then do the size, the kind-specific checks and
 * the scan hook run, cheapest and most decisive first, the scanner last
 * because it is the slowest and the only one that can be offline.
 *
 * Throws; writes nothing. The repository calls this before it opens its
 * transaction, so a refused file leaves no row and no orphan on the disk.
 */
final readonly class UploadGuard
{
    private const int MAX_UNCOMPRESSED_BYTES = 1_073_741_824;

    public function __construct(
        private ImageProcessor $processor,
        private Dispatcher $events,
        private Container $container,
    ) {}

    /**
     * @param  MediaKind|null  $expected  the kind a replacement must keep
     *
     * @throws ExtensionMismatch|UnsupportedFileType|KindMismatch|FileTooLarge|UnreadableImage|UploadRejected
     */
    public function inspect(string $binary, string $filename, ?MediaKind $expected = null): Inspection
    {
        $path = tempnam(sys_get_temp_dir(), 'media-upload-');

        if ($path === false || file_put_contents($path, $binary) === false) {
            throw new RuntimeException('Could not create a temporary file to inspect the upload.');
        }

        try {
            return $this->run($path, $binary, $filename, $expected);
        } finally {
            @unlink($path);
        }
    }

    private function run(string $path, string $binary, string $filename, ?MediaKind $expected): Inspection
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = FileTypes::detect($path, $extension);

        if (! FileTypes::extensionAgrees($mime, $extension)) {
            throw ExtensionMismatch::make($mime);
        }

        $kind = MediaKind::fromMime($mime) ?? throw UnsupportedFileType::make($mime);

        if ($expected !== null && $expected !== $kind) {
            throw KindMismatch::make($expected, $kind);
        }

        $limitKb = MediaKinds::maxSizeKb($kind);

        if (strlen($binary) > $limitKb * 1024) {
            throw FileTooLarge::make($kind, $limitKb);
        }

        $dimensions = $this->kindChecks($path, $binary, $filename, $mime, $kind);

        $this->events->dispatch(new AssetUploading($path, $filename, $mime, $kind, strlen($binary)));
        $this->scan($path);

        return new Inspection(
            $mime,
            $kind,
            $dimensions[0] ?? null,
            $dimensions[1] ?? null,
            $mime === 'application/pdf' ? PdfPages::count($binary) : null,
        );
    }

    /** @return array{0: int, 1: int}|null */
    private function kindChecks(string $path, string $binary, string $filename, string $mime, MediaKind $kind): ?array
    {
        if ($kind === MediaKind::Image) {
            return $this->processor->dimensions($binary) ?? throw UnreadableImage::make($filename);
        }

        if ($mime === 'application/pdf' && ! str_starts_with($binary, '%PDF-')) {
            throw UnsupportedFileType::make($mime);
        }

        if (str_starts_with($mime, 'application/zip') || str_contains($mime, 'openxmlformats') || str_contains($mime, 'opendocument')) {
            $this->zipChecks($path, str_contains($mime, 'openxmlformats'));
        }

        return null;
    }

    /** Entry count and uncompressed size bound a zip bomb; a VBA project is a macro, whatever the file is called. */
    private function zipChecks(string $path, bool $ooxml): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw UnsupportedFileType::make('application/zip');
        }

        try {
            if ($zip->numFiles > MediaConfig::int('uploads.archive.max_entries', 5000)) {
                throw UploadRejected::because('the archive holds too many files.');
            }

            $contentTypes = $ooxml ? $zip->getFromName('[Content_Types].xml') : false;

            if (is_string($contentTypes) && stripos($contentTypes, 'macroEnabled') !== false) {
                throw UnsupportedFileType::macros();
            }

            $total = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $total += $entry === false ? 0 : $entry['size'];

                if ($ooxml && $entry !== false && str_ends_with(strtolower($entry['name']), 'vbaproject.bin')) {
                    throw UnsupportedFileType::macros();
                }
            }

            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw UploadRejected::because('the archive expands to more than 1 GB.');
            }
        } finally {
            $zip->close();
        }
    }

    private function scan(string $path): void
    {
        $class = MediaConfig::nullableString('uploads.scanner');

        if ($class === null) {
            return;
        }

        $scanner = $this->container->make($class);

        if (! $scanner instanceof FileScanner) {
            throw new LogicException(sprintf('media-library.uploads.scanner [%s] must implement %s.', $class, FileScanner::class));
        }

        $result = $scanner->scan($path);

        if (! $result->clean) {
            throw UploadRejected::because($result->reason);
        }
    }
}
