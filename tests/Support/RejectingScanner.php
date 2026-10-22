<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Tests\Support;

use Banyan\FilamentMediaLibrary\Contracts\FileScanner;
use Banyan\FilamentMediaLibrary\Scanning\ScanResult;

/** Calls the scanner makes are recorded in `$seen`; files holding "EICAR" are infected. */
final class RejectingScanner implements FileScanner
{
    /** @var list<string> contents of every file scanned */
    public static array $seen = [];

    public function scan(string $path): ScanResult
    {
        $contents = (string) file_get_contents($path);
        self::$seen[] = $contents;

        return str_contains($contents, 'EICAR') ? ScanResult::infected('Eicar-Test-Signature detected.') : ScanResult::clean();
    }
}
