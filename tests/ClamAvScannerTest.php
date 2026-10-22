<?php

declare(strict_types=1);

use Banyan\FilamentMediaLibrary\Scanning\ClamAvScanner;

covers(ClamAvScanner::class);

/**
 * A one-shot fake clamd in a child process: it reads the INSTREAM frames up to
 * the zero-length terminator, then sends `$reply`. Returns the address and the
 * process, or null when this environment cannot run one.
 *
 * @return array{0: string, 1: resource, 2: string}|null
 */
function fakeClamd(string $reply): ?array
{
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $code, $message);

    if ($probe === false || ! function_exists('proc_open')) {
        return null;
    }

    $address = (string) stream_socket_get_name($probe, false);
    fclose($probe);

    $script = <<<'PHP'
        $server = stream_socket_server('tcp://'.$argv[1]);
        fwrite(STDOUT, "ready\n");
        $conn = stream_socket_accept($server, 10);
        $received = '';
        while (! str_ends_with($received, "\0\0\0\0") || ! str_starts_with($received, "zINSTREAM\0")) {
            $chunk = fread($conn, 65536);
            if ($chunk === '' || $chunk === false) { break; }
            $received .= $chunk;
        }
        file_put_contents($argv[3], $received);
        fwrite($conn, $argv[2]."\0");
        fclose($conn);
        PHP;

    $received = (string) tempnam(sys_get_temp_dir(), 'clamd-');
    $process = proc_open([PHP_BINARY, '-r', $script, $address, $reply, $received], [1 => ['pipe', 'w']], $pipes);

    if (! is_resource($process) || fgets($pipes[1]) !== "ready\n") {
        return null;
    }

    return ['tcp://'.$address, $process, $received];
}

it('streams the file to clamd and treats an OK reply as clean', function (): void {
    $clamd = fakeClamd('stream: OK');
    $clamd !== null || $this->markTestSkipped('Cannot start a fake clamd here.');
    [$address, $process, $received] = $clamd;
    config()->set('media-library.uploads.clamav.socket', $address);
    $file = (string) tempnam(sys_get_temp_dir(), 'scan-');
    file_put_contents($file, 'hello clamav');

    $result = (new ClamAvScanner)->scan($file);
    proc_close($process);

    expect($result->clean)->toBeTrue()
        // zINSTREAM, one length-prefixed chunk, then the zero-length terminator.
        ->and(file_get_contents($received))->toBe("zINSTREAM\0".pack('N', 12).'hello clamav'.pack('N', 0));
});

it('reports the signature clamd found, without a file name', function (): void {
    $clamd = fakeClamd('stream: Eicar-Test-Signature FOUND');
    $clamd !== null || $this->markTestSkipped('Cannot start a fake clamd here.');
    config()->set('media-library.uploads.clamav.socket', $clamd[0]);
    $file = (string) tempnam(sys_get_temp_dir(), 'scan-');
    file_put_contents($file, 'X5O!P%@AP');

    $result = (new ClamAvScanner)->scan($file);
    proc_close($clamd[1]);

    expect($result->clean)->toBeFalse()->and($result->reason)->toBe('Eicar-Test-Signature detected.');
});

it('fails closed when clamd answers with an error', function (): void {
    $clamd = fakeClamd('INSTREAM size limit exceeded. ERROR');
    $clamd !== null || $this->markTestSkipped('Cannot start a fake clamd here.');
    config()->set('media-library.uploads.clamav.socket', $clamd[0]);
    $file = (string) tempnam(sys_get_temp_dir(), 'scan-');
    file_put_contents($file, 'data');

    expect(fn () => (new ClamAvScanner)->scan($file))->toThrow(RuntimeException::class, 'returned an error');
    proc_close($clamd[1]);
});

it('fails closed when clamd cannot be reached', function (): void {
    config()->set('media-library.uploads.clamav.socket', 'tcp://127.0.0.1:1');
    $file = (string) tempnam(sys_get_temp_dir(), 'scan-');
    file_put_contents($file, 'data');

    expect(fn () => (new ClamAvScanner)->scan($file))->toThrow(RuntimeException::class, 'unavailable');
});
