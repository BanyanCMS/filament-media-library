<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Contracts;

use Banyan\FilamentMediaLibrary\Scanning\ScanResult;

/**
 * A pre-upload scanner, named in `media-library.uploads.scanner`.
 *
 * It runs after every built-in check and before anything is written, on a
 * temporary copy of the bytes. A scanner that cannot reach its engine should
 * throw, not return clean: an unscanned file must not read as a safe one.
 */
interface FileScanner
{
    public function scan(string $path): ScanResult;
}
