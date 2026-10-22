<?php

declare(strict_types=1);

namespace Banyan\FilamentMediaLibrary\Scanning;

use Banyan\FilamentMediaLibrary\Contracts\FileScanner;
use Banyan\FilamentMediaLibrary\Support\MediaConfig;
use RuntimeException;

/**
 * An opt-in ClamAV scanner over clamd's INSTREAM command.
 *
 *     'uploads' => ['scanner' => ClamAvScanner::class, ...]
 *     'uploads' => ['clamav' => ['socket' => 'unix:///var/run/clamav/clamd.ctl']]   // or tcp://127.0.0.1:3310
 *
 * Not a dependency and not enabled by default. When clamd cannot be reached it
 * throws rather than reporting clean: failing open would make "scanning is on"
 * mean nothing during exactly the outage an attacker would pick.
 */
final class ClamAvScanner implements FileScanner
{
    private const int CHUNK = 8192;

    public function scan(string $path): ScanResult
    {
        $address = MediaConfig::string('uploads.clamav.socket', 'unix:///var/run/clamav/clamd.ctl');
        $stream = @stream_socket_client($address, $code, $message, MediaConfig::int('uploads.clamav.timeout', 10));

        if ($stream === false) {
            throw new RuntimeException('The virus scanner is unavailable.');
        }

        $file = fopen($path, 'rb');

        if ($file === false) {
            fclose($stream);

            throw new RuntimeException('The upload could not be read for scanning.');
        }

        try {
            fwrite($stream, "zINSTREAM\0");

            while (! feof($file)) {
                $chunk = (string) fread($file, self::CHUNK);

                if ($chunk !== '') {
                    fwrite($stream, pack('N', strlen($chunk)).$chunk);
                }
            }

            fwrite($stream, pack('N', 0));
            $reply = trim((string) stream_get_contents($stream), "\0 \n");
        } finally {
            fclose($file);
            fclose($stream);
        }

        return $this->verdict($reply);
    }

    /** "stream: OK", "stream: Eicar-Test-Signature FOUND" or "INSTREAM size limit exceeded. ERROR". */
    private function verdict(string $reply): ScanResult
    {
        if (str_ends_with($reply, 'OK')) {
            return ScanResult::clean();
        }

        if (str_ends_with($reply, 'FOUND')) {
            return ScanResult::infected(trim(substr($reply, (int) strpos($reply, ':') + 1, -5)).' detected.');
        }

        throw new RuntimeException('The virus scanner returned an error.');
    }
}
