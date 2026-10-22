<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Events;

use Banyan\FilamentMediaLibrary\Enums\MediaKind;
use Banyan\FilamentMediaLibrary\Exceptions\UploadRejected;

/**
 * A file passed every built-in check and is about to be written.
 *
 * Not a {@see MediaEvent}: no asset exists yet, so there is nothing to audit.
 * A listener that throws {@see UploadRejected}
 * stops the upload before the disk or the database is touched. `$path` is a
 * temporary copy that exists only while listeners run.
 */
final readonly class AssetUploading
{
    public function __construct(
        public string $path,
        public string $filename,
        public string $mimeType,
        public MediaKind $kind,
        public int $sizeBytes,
    ) {}
}
